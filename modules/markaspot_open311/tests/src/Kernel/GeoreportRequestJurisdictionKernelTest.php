<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_open311\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolver;
use Drupal\markaspot_group\Service\JurisdictionScopeValidator;
use Drupal\markaspot_group\Service\WorkspaceVisibilityInterface;
use Drupal\markaspot_open311\Plugin\rest\resource\GeoreportRequestResource;
use Drupal\markaspot_open311\Service\GeoreportProcessorService;
use Drupal\markaspot_open311\Service\StatusClassifier;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeAccessRebuild;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Tests request ID disambiguation with real jurisdiction and node storage.
 *
 * @group markaspot_open311
 */
#[RunTestsInSeparateProcesses]
final class GeoreportRequestJurisdictionKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'filter', 'text', 'node', 'taxonomy',
    'options', 'entity', 'flexible_permissions', 'group', 'markaspot_request_id',
  ];

  /**
   * Config owned by modules not installed in this minimal resource fixture.
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = [
    'markaspot_open311.settings',
    'services_api_key_auth.settings',
  ];

  /**
   * Workspace IDs indexed by slug.
   *
   * @var array<string, int>
   */
  private array $workspaces = [];

  /**
   * Workspaces whose requests fail the resource's anonymous scope check.
   *
   * @var int[]
   */
  private array $hiddenWorkspaces = [];

  /**
   * Form-only workspaces and the reader's permitted organisation IDs.
   *
   * @var int[]
   */
  private array $formOnlyWorkspaces = [];

  /**
   * The current reader's organisation scope for the visibility mock.
   *
   * @var int[]
   */
  private array $readerOrganisationIds = [];

  /**
   * API key workspace scope supplied to the real scope validator.
   *
   * @var int[]
   */
  private array $keyWorkspaceIds = [];

  /**
   * The real processor, including list normalization and response shaping.
   */
  private GeoreportProcessorService $processor;

  /**
   * Single-request resource under test.
   */
  private GeoreportRequestResource $resource;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', 'node_access');
    foreach (['user', 'node', 'taxonomy_term', 'group', 'group_relationship'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installConfig(['filter', 'node', 'user', 'group']);
    Role::load('anonymous')->grantPermission('access content')->save();
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    NodeType::create(['type' => 'service_request', 'name' => 'Request'])->save();
    $this->createField('node', 'service_request', 'body', 'text_long');
    GroupType::create(['id' => 'jur', 'label' => 'Workspace'])->save();
    $this->createField('group', 'jur', 'field_slug', 'string');
    $this->createField('group', 'jur', 'field_parent_jurisdiction', 'entity_reference', 'group');
    $this->createField('node', 'service_request', 'field_jurisdiction', 'entity_reference', 'group');
    $this->createField('node', 'service_request', 'field_organisation', 'entity_reference', 'group');
    $this->createField('node', 'service_request', 'field_category', 'entity_reference', 'taxonomy_term');
    $this->createField('node', 'service_request', 'field_status', 'entity_reference', 'taxonomy_term');
    $this->config('markaspot_open311.settings')
      ->set('bundle', 'service_request')->set('jurisdiction_group_type', 'jur')->save();
    $this->config('services_api_key_auth.settings')->set('api_key_get_parameter_name', 'api_key')->save();

    foreach (['workspace-a', 'workspace-child', 'workspace-second-child', 'workspace-sibling', 'workspace-empty'] as $slug) {
      $group = Group::create(['type' => 'jur', 'label' => $slug, 'field_slug' => $slug, 'status' => 1]);
      if (in_array($slug, ['workspace-child', 'workspace-second-child'], TRUE)) {
        $group->set('field_parent_jurisdiction', $this->workspaces['workspace-a']);
      }
      $group->save();
      $this->workspaces[$slug] = (int) $group->id();
      if (!in_array($slug, ['workspace-empty', 'workspace-second-child'], TRUE)) {
        $this->createReport($slug, '2-2026');
      }
    }
    $this->createReport('workspace-child', 'child-only');
    $this->createReport('workspace-child', 'two-children');
    $this->createReport('workspace-second-child', 'two-children');
    $this->createReport('workspace-child', 'unpublished-child', FALSE);
    $this->createReport('workspace-a', 'unpublished', FALSE);
    $this->container->get(NodeAccessRebuild::class)->rebuild();

    $hierarchy = new JurisdictionHierarchyResolver(
      $this->container->get('entity_type.manager'),
      $this->container->get('database'),
      $this->container->get('logger.factory'),
      $this->container->get('config.factory'),
    );
    $this->keyWorkspaceIds = [$this->workspaces['workspace-a']];
    $this->initializeResource($hierarchy);
  }

  /**
   * Wires real query and scope services with explicit visibility boundaries.
   */
  private function initializeResource(JurisdictionHierarchyResolver $hierarchy): void {
    $this->processor = new GeoreportProcessorService(
      $this->container->get('config.factory'),
      $this->container->get('current_user'),
      $this->container->get('datetime.time'),
      $this->container->get('request_stack'),
      $this->container->get('entity_type.manager'),
      $this->container->get('file_url_generator'),
      $this->container->get('module_handler'),
      $this->container->get('entity_field.manager'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('token'),
      $this->container->get('language_manager'),
      $this->container->get('database'),
      $this->container->get('file_system'),
      $this->container->get('http_client'),
      $this->container->get('messenger'),
      $this->container->get('account_switcher'),
      new StatusClassifier($this->container->get('config.factory'), $this->container->get('entity_type.manager'), $this->container->get('cache.default')),
      $hierarchy,
      $this->container->get('logger.factory')->get('markaspot_open311'),
    );
    $visibility = $this->createMock(WorkspaceVisibilityInterface::class);
    $visibility->method('canAnonymousView')->willReturnCallback(
      fn(int $id): bool => !in_array($id, array_merge($this->hiddenWorkspaces, $this->formOnlyWorkspaces), TRUE),
    );
    $visibility->method('getVisibility')->willReturnCallback(
      fn(int $id): string => in_array($id, $this->formOnlyWorkspaces, TRUE) ? 'form_only' : 'public',
    );
    $visibility->method('allowsReportReadFor')->willReturnCallback(
      fn(AccountInterface $account, int $id, array $organisationIds): bool => !$account->isAnonymous()
        && array_intersect($organisationIds, $this->readerOrganisationIds) !== [],
    );
    $validator = $this->getMockBuilder(JurisdictionScopeValidator::class)
      ->setConstructorArgs([
        $this->container->get('database'),
        $this->container->get('logger.factory')->get('markaspot_open311'),
        $this->container->get('config.factory'),
      ])->onlyMethods(['getAllowedJurisdictionIds'])->getMock();
    $validator->method('getAllowedJurisdictionIds')->willReturnCallback(fn(): array => $this->keyWorkspaceIds);
    $this->resource = new GeoreportRequestResource(
      [], 'georeport_request_resource', [], ['json'],
      $this->container->get('logger.factory')->get('markaspot_open311'),
      $this->container->get('current_user'),
      $this->container->get('config.factory'),
      $this->container->get('string_translation'),
      $this->container->get('datetime.time'),
      $this->container->get('request_stack'),
      $this->container->get('entity_type.manager'),
      $this->processor,
      $this->container->get('language_manager'),
      $this->container->get('flood'),
      $validator,
      $hierarchy,
      $visibility,
    );
  }

  /**
   * Slugs and group IDs select only the claimed workspace's published node.
   */
  public function testExplicitWorkspaceDisambiguates(): void {
    foreach (['workspace-a', 'workspace-child', 'workspace-sibling'] as $slug) {
      foreach ([$slug, (string) $this->workspaces[$slug]] as $claim) {
        $parameters = ['jurisdiction_id' => $claim];
        $query = $this->processor->createNodeQuery($parameters, $this->container->get('current_user'));
        $query->condition('request_id', '2-2026');
        $listed = $this->processor->getResults($query, $this->container->get('current_user'), $parameters);
        // Parent lists still aggregate children; a detail read prefers a
        // matching request in the claimed workspace.
        $this->assertEqualsCanonicalizing(
          $slug === 'workspace-a' ? ['workspace-a', 'workspace-child'] : [$slug],
          array_column($listed, 'description'),
        );
        $result = $this->read('2-2026', $parameters);
        $this->assertCount(1, $result);
        $this->assertSame('2-2026', $result[0]['service_request_id']);
        $this->assertSame($slug, $result[0]['description']);
      }
    }
  }

  /**
   * A parent request wins over a child request with the same ID.
   */
  public function testParentRequestTakesPrecedenceOverChild(): void {
    foreach (['workspace-a', (string) $this->workspaces['workspace-a']] as $claim) {
      $result = $this->read('2-2026', ['jurisdiction_id' => $claim]);
      $this->assertCount(1, $result);
      $this->assertSame('workspace-a', $result[0]['description']);
    }
  }

  /**
   * Foreign workspaces and unknown IDs retain the empty-array response.
   */
  public function testForeignWorkspaceAndUnknownRequestReturnEmptyArray(): void {
    foreach (['workspace-empty', (string) $this->workspaces['workspace-empty']] as $claim) {
      $this->assertSame([], $this->read('2-2026', ['jurisdiction_id' => $claim]));
    }
    $this->assertSame([], $this->read('unknown-request', ['jurisdiction_id' => 'workspace-a']));
    $this->assertSame([], $this->read('unknown-request', []));
  }

  /**
   * A parent claim retains access to a unique child-only request.
   */
  public function testParentReadsUniqueChildRequest(): void {
    foreach (['workspace-a', (string) $this->workspaces['workspace-a']] as $claim) {
      $result = $this->read('child-only', ['jurisdiction_id' => $claim]);
      $this->assertCount(1, $result);
      $this->assertSame('child-only', $result[0]['service_request_id']);
      $this->assertSame('workspace-child', $result[0]['description']);
    }
  }

  /**
   * An exact candidate rejected by scope cannot hide a visible child match.
   */
  public function testRejectedExactCandidateFallsBackToVisibleChild(): void {
    $this->hiddenWorkspaces = [$this->workspaces['workspace-a']];
    $result = $this->read('2-2026', ['jurisdiction_id' => 'workspace-a']);
    $this->assertCount(1, $result);
    $this->assertSame('workspace-child', $result[0]['description']);
  }

  /**
   * A parent cannot disambiguate an ID shared only by two children.
   */
  public function testParentWithTwoChildMatchesIsAmbiguous(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('jurisdiction_id required to disambiguate service_request_id.');
    $this->read('two-children', ['jurisdiction_id' => 'workspace-a']);
  }

  /**
   * Duplicate IDs inside the exact workspace still fail closed.
   */
  public function testDuplicateInsideExactWorkspaceIsAmbiguous(): void {
    $this->createReport('workspace-a', '2-2026');
    $this->expectException(BadRequestHttpException::class);
    $this->read('2-2026', ['jurisdiction_id' => 'workspace-a']);
  }

  /**
   * Unpublished requests stay hidden in both exact and fallback lookups.
   */
  public function testUnpublishedRequestsReturnEmptyArray(): void {
    foreach (['unpublished', 'unpublished-child'] as $id) {
      $this->assertSame([], $this->read($id, ['jurisdiction_id' => 'workspace-a']));
    }
  }

  /**
   * Without a claim, duplicate IDs still produce the disambiguation error.
   */
  public function testMissingJurisdictionIsAmbiguous(): void {
    $this->expectException(BadRequestHttpException::class);
    $this->expectExceptionMessage('jurisdiction_id required to disambiguate service_request_id.');
    $this->read('2-2026', []);
  }

  /**
   * Session reads retain publication access and hierarchical fallback.
   */
  public function testAuthenticatedReadsKeepWorkspaceAndPublicationScope(): void {
    $this->authenticateReader();
    foreach (['workspace-a', (string) $this->workspaces['workspace-a']] as $claim) {
      $result = $this->read('2-2026', ['jurisdiction_id' => $claim]);
      $this->assertCount(1, $result);
      $this->assertSame('workspace-a', $result[0]['description']);
      $child = $this->read('child-only', ['jurisdiction_id' => $claim]);
      $this->assertCount(1, $child);
      $this->assertSame('workspace-child', $child[0]['description']);
    }
    foreach (['unpublished', 'unpublished-child'] as $id) {
      $this->assertCount(1, $this->read($id, ['jurisdiction_id' => 'workspace-a']));
    }
  }

  /**
   * API keys retain hierarchical lookup and anonymous publication rules.
   */
  public function testApiKeyRetainsChildReadsAndPublicationScope(): void {
    $this->authenticateReader();
    $parameters = ['jurisdiction_id' => 'workspace-a', 'api_key' => 'test-key'];
    $result = $this->read('child-only', $parameters);
    $this->assertCount(1, $result);
    $this->assertSame('workspace-child', $result[0]['description']);
    foreach (['unpublished', 'unpublished-child', 'unknown-request'] as $id) {
      $this->assertSame([], $this->read($id, $parameters));
    }
  }

  /**
   * API keys prefer an exact parent match while retaining their read scope.
   */
  public function testApiKeyParentRequestTakesPrecedenceOverChild(): void {
    $this->authenticateReader();
    $result = $this->read('2-2026', ['jurisdiction_id' => 'workspace-a', 'api_key' => 'test-key']);
    $this->assertCount(1, $result);
    $this->assertSame('workspace-a', $result[0]['description']);
  }

  /**
   * Exact selection must not bypass an API key's membership boundary.
   */
  public function testApiKeyCannotClaimForeignWorkspace(): void {
    $this->authenticateReader();
    $this->expectException(AccessDeniedHttpException::class);
    $this->read('2-2026', ['jurisdiction_id' => 'workspace-sibling', 'api_key' => 'test-key']);
  }

  /**
   * An API key with no workspace scope cannot use an exact match.
   */
  public function testApiKeyWithoutScopeCannotReadExactMatch(): void {
    $this->authenticateReader();
    $this->keyWorkspaceIds = [];
    $this->expectException(AccessDeniedHttpException::class);
    $this->expectExceptionMessage('key has no jurisdiction scope');
    $this->read('2-2026', ['jurisdiction_id' => 'workspace-a', 'api_key' => 'test-key']);
  }

  /**
   * Visibility still denies an exact match to an otherwise authorized key.
   */
  public function testApiKeyCannotReadHiddenExactWorkspace(): void {
    $this->authenticateReader();
    $this->hiddenWorkspaces = [$this->workspaces['workspace-a']];
    $this->assertSame([], $this->read('2-2026', ['jurisdiction_id' => 'workspace-a', 'api_key' => 'test-key']));
  }

  /**
   * A real hierarchy disambiguates a root and two descendants sharing an ID.
   */
  public function testRootAndTwoDescendants(): void {
    $this->assertRootAndDescendantReads();
  }

  /**
   * Flat storage supports a platform resolver that includes every workspace.
   */
  public function testFlatPlatformRootAndTwoDescendants(): void {
    FieldStorageConfig::load('group.field_parent_jurisdiction')->delete();
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache();
    $this->assertFalse(Group::load($this->workspaces['workspace-a'])->hasField('field_parent_jurisdiction'));
    // Model the platform's reported resolver contract explicitly. The generic
    // resolver in this fixture treats groups without a parent field as roots.
    $hierarchy = $this->getMockBuilder(JurisdictionHierarchyResolver::class)
      ->setConstructorArgs([
        $this->container->get('entity_type.manager'),
        $this->container->get('database'),
        $this->container->get('logger.factory'),
        $this->container->get('config.factory'),
      ])->onlyMethods(['getDescendantIds'])->getMock();
    $hierarchy->method('getDescendantIds')->willReturnCallback(
      fn(int $id): array => $id === $this->workspaces['workspace-a'] ? array_values($this->workspaces) : [$id],
    );
    $this->initializeResource($hierarchy);
    $this->assertRootAndDescendantReads();
  }

  /**
   * Checks root precedence, exact descendants, and child-only ambiguity.
   */
  private function assertRootAndDescendantReads(): void {
    $this->createReport('workspace-second-child', '2-2026');
    $this->keyWorkspaceIds = array_values($this->workspaces);
    // Exercise the API-key root claim first for the old-base regression.
    $this->authenticateReader();
    foreach ([TRUE, FALSE] as $apiKey) {
      if (!$apiKey) {
        $this->container->get('current_user')->setAccount(new AnonymousUserSession());
      }
      foreach (['workspace-a', 'workspace-child', 'workspace-second-child'] as $slug) {
        foreach ([$slug, (string) $this->workspaces[$slug]] as $claim) {
          $parameters = ['jurisdiction_id' => $claim];
          if ($apiKey) {
            $parameters['api_key'] = 'test-key';
          }
          $result = $this->read('2-2026', $parameters);
          $this->assertCount(1, $result);
          $this->assertSame($slug, $result[0]['description']);
          if ($slug === 'workspace-a') {
            try {
              $this->read('two-children', $parameters);
              $this->fail('A root claim must not resolve two child-only matches.');
            }
            catch (BadRequestHttpException $e) {
              $this->assertSame(400, $e->getStatusCode());
              $this->assertSame('jurisdiction_id required to disambiguate service_request_id.', $e->getMessage());
            }
          }
        }
      }
    }
  }

  /**
   * Hidden workspace claims cannot expose their own or a sibling's request.
   */
  public function testAnonymousHiddenWorkspaceAndPublicSiblingReturnEmpty(): void {
    $this->hiddenWorkspaces = [$this->workspaces['workspace-sibling']];
    $this->createReport('workspace-sibling', 'hidden-only');
    foreach (['workspace-sibling', (string) $this->workspaces['workspace-sibling']] as $claim) {
      foreach (['hidden-only', '2-2026'] as $id) {
        $this->assertSame([], $this->read($id, ['jurisdiction_id' => $claim]));
      }
    }
  }

  /**
   * Invalid claims never select among duplicates or leak group existence.
   */
  public function testInvalidClaimsRemainClosed(): void {
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $org = Group::create(['type' => 'org', 'label' => 'Example organisation']);
    $org->save();
    $claims = ['unknown-workspace', (string) $org->id()];
    foreach ($claims as $claim) {
      $this->assertSame([], $this->read('2-2026', ['jurisdiction_id' => $claim]));
    }
    $this->authenticateReader();
    foreach ($claims as $claim) {
      try {
        $this->read('2-2026', ['jurisdiction_id' => $claim]);
        $this->fail('Invalid session claims must be rejected.');
      }
      catch (BadRequestHttpException $e) {
        $this->assertSame(400, $e->getStatusCode());
        $this->assertSame('Invalid jurisdiction_id.', $e->getMessage());
      }
    }
  }

  /**
   * Form-only exact reads require the reader's organisation scope.
   */
  public function testFormOnlyClaimRequiresOrganisationScope(): void {
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $org = Group::create(['type' => 'org', 'label' => 'Example organisation']);
    $org->save();
    $report = $this->createReport('workspace-sibling', 'form-only');
    $report->set('field_organisation', $org->id())->save();
    $this->formOnlyWorkspaces = [$this->workspaces['workspace-sibling']];
    $parameters = ['jurisdiction_id' => 'workspace-sibling'];
    $this->assertSame([], $this->read('form-only', $parameters));
    $this->authenticateReader();
    $this->assertSame([], $this->read('form-only', $parameters));
    $this->readerOrganisationIds = [(int) $org->id()];
    $result = $this->read('form-only', $parameters);
    $this->assertCount(1, $result);
    $this->assertSame('workspace-sibling', $result[0]['description']);
  }

  /**
   * All jurisdictions must be visible even when the exact match is public.
   */
  public function testMultivalueExactMatchCannotExposeHiddenWorkspace(): void {
    $report = $this->createReport('workspace-a', 'multivalue');
    $report->set('field_jurisdiction', [
      $this->workspaces['workspace-a'], $this->workspaces['workspace-sibling'],
    ])->save();
    $this->hiddenWorkspaces = [$this->workspaces['workspace-sibling']];
    $parameters = ['jurisdiction_id' => 'workspace-a'];
    $this->assertSame([], $this->read('multivalue', $parameters));
    $this->authenticateReader();
    $parameters['api_key'] = 'test-key';
    $this->assertSame([], $this->read('multivalue', $parameters));
  }

  /**
   * Authenticates a non-root reader who can view unpublished nodes.
   */
  private function authenticateReader(): void {
    User::create(['name' => 'reserved-root'])->save();
    $user = User::create(['name' => 'api-reader']);
    $user->save();
    Role::load('authenticated')->grantPermission('bypass node access')->save();
    $this->container->get('current_user')->setAccount($user);
  }

  /**
   * Invokes the actual GET resource with query parameters.
   */
  private function read(string $id, array $parameters): array {
    $stack = $this->container->get('request_stack');
    $stack->push(Request::create('/georeport/v2/requests/' . $id . '.json', 'GET', $parameters));
    try {
      return $this->resource->get($id);
    }
    finally {
      $stack->pop();
    }
  }

  /**
   * Creates a request with an explicit ID and a distinguishable description.
   */
  private function createReport(string $slug, string $requestId, bool $published = TRUE): Node {
    $node = Node::create([
      'type' => 'service_request', 'title' => $slug, 'body' => $slug,
      'request_id' => $requestId, 'status' => (int) $published,
      'field_jurisdiction' => $this->workspaces[$slug],
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates a field for the minimal real entity fixture.
   */
  private function createField(string $entityType, string $bundle, string $name, string $type, ?string $target = NULL): void {
    FieldStorageConfig::create([
      'entity_type' => $entityType, 'field_name' => $name, 'type' => $type,
      'settings' => $target ? ['target_type' => $target] : [],
      'cardinality' => $name === 'field_jurisdiction' ? -1 : 1,
    ])->save();
    FieldConfig::create([
      'entity_type' => $entityType, 'bundle' => $bundle, 'field_name' => $name,
    ])->save();
  }

}
