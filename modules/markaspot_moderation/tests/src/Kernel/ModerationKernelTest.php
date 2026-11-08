<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_moderation\Kernel;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_moderation\Controller\ModerationController;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__, 4) . '/markaspot_group/src/Service/TenantAdminHelper.php';
require_once dirname(__DIR__, 4) . '/service_request/src/RevisionLog.php';

/**
 * Tests flag persistence, moderation lifecycle and jurisdiction access.
 *
 * @group markaspot_moderation
 */
#[RunTestsInSeparateProcesses]
final class ModerationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'entity',
    'flexible_permissions', 'group', 'gnode', 'markaspot_request_id',
    'markaspot_moderation',
  ];

  /**
   * Exercise actual permissions even for the first saved user.
   */
  protected bool $usesSuperUserAccessPolicy = FALSE;

  /**
   * The jurisdiction containing the report.
   */
  private Group $jurisdiction;

  /**
   * The persisted report.
   */
  private Node $report;

  /**
   * The real endpoint controller.
   */
  private ModerationController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('markaspot_moderation', ['markaspot_moderation_flags']);
    $this->installConfig(['system', 'user', 'node', 'group', 'markaspot_moderation']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    $type = GroupType::create(['id' => 'jur', 'label' => 'Jurisdiction']);
    $type->save();
    $types = $this->container->get('entity_type.manager')->getStorage('group_relationship_type');
    foreach (['group_membership', 'group_node:service_request'] as $plugin) {
      if (!$types->loadByProperties(['group_type' => 'jur', 'content_plugin' => $plugin])) {
        $types->createFromPlugin($type, $plugin)->save();
      }
    }
    GroupRole::create([
      'id' => 'jur-tenant_admin', 'label' => 'Tenant administrator',
      'group_type' => 'jur', 'scope' => 'individual',
    ])->save();
    $this->jurisdiction = Group::create(['type' => 'jur', 'label' => 'Jurisdiction A']);
    $this->jurisdiction->save();
    $this->report = Node::create([
      'type' => 'service_request', 'title' => 'Flagged report',
      'request_id' => 'TEST-1', 'status' => 1,
    ]);
    $this->report->save();
    $this->jurisdiction->addRelationship($this->report, 'group_node:service_request');
    $this->controller = ModerationController::create($this->container);
  }

  /**
   * The endpoint persists active flags and hides exactly at the threshold.
   */
  public function testSubmissionAndAutoHide(): void {
    $revision = $this->report->getRevisionId();
    for ($index = 1; $index <= 3; $index++) {
      $response = $this->controller->submitFlag($this->flagRequest('192.0.2.' . $index));
      $this->assertSame(201, $response->getStatusCode(), (string) $response->getContent());
      $payload = json_decode((string) $response->getContent(), TRUE);
      $this->assertTrue($payload['success']);
      $row = $this->container->get('database')->select('markaspot_moderation_flags', 'f')
        ->fields('f')->condition('fid', $payload['flagId'])->execute()->fetchAssoc();
      $this->assertSame((int) $this->report->id(), (int) $row['nid']);
      $this->assertSame((int) $this->jurisdiction->id(), (int) $row['jurisdiction_id']);
      $this->assertSame('TEST-1', $row['service_request_id']);
      $this->assertSame('spam', $row['reason']);
      $this->assertSame('Repeated advertising', $row['details']);
      $this->assertSame(0, (int) $row['dismissed']);
      $this->assertSame(hash_hmac('sha256', '192.0.2.' . $index, 'mas_default_salt'), $row['ip_hash']);
      $this->assertSame($index < 3, $this->reloadReport()->isPublished());
    }
    $this->assertNotSame($revision, $this->reloadReport()->getRevisionId());
    $flags = $this->container->get('markaspot_moderation.service')->getFlagsForRequest((int) $this->report->id());
    $this->assertCount(3, $flags);
    $this->assertArrayNotHasKey('ip_hash', $flags[0]);
    $this->assertArrayNotHasKey('session_hash', $flags[0]);
  }

  /**
   * Duplicate sources and invalid submissions do not add flag rows.
   */
  public function testDuplicateAndInvalidSubmission(): void {
    $this->assertSame(201, $this->controller->submitFlag($this->flagRequest())->getStatusCode());
    $this->assertSame(409, $this->controller->submitFlag($this->flagRequest())->getStatusCode());
    $unknown = $this->flagRequest('192.0.2.2', ['service_request_id' => 'MISSING']);
    $this->assertSame(404, $this->controller->submitFlag($unknown)->getStatusCode());
    $invalid = $this->flagRequest('192.0.2.3', ['reason' => 'other', 'details' => '']);
    $this->assertSame(400, $this->controller->submitFlag($invalid)->getStatusCode());
    $flags = $this->container->get('markaspot_moderation.service')->getFlagsForRequest((int) $this->report->id());
    $this->assertCount(1, $flags);
    $this->assertTrue($this->reloadReport()->isPublished());
  }

  /**
   * Route permission gates separate public submission from staff review.
   */
  public function testRoutePermissions(): void {
    $this->container->get('router.builder')->rebuild();
    $access = $this->container->get('access_manager');
    $anonymous = new AnonymousUserSession();
    $this->assertFalse($access->checkNamedRoute('markaspot_moderation.submit_flag', [], $anonymous));
    $role = Role::load('anonymous');
    $role->grantPermission('submit flags')->save();
    $this->assertTrue($access->checkNamedRoute('markaspot_moderation.submit_flag', [], $anonymous));
    $this->assertFalse($access->checkNamedRoute('markaspot_moderation.flagged_requests', [], $anonymous));
    $staff = $this->staff('staff');
    $this->assertTrue($access->checkNamedRoute('markaspot_moderation.flagged_requests', [], $staff));
    $this->assertFalse($access->checkNamedRoute('markaspot_moderation.delete_request', ['nid' => $this->report->id()], $staff));
  }

  /**
   * Moderation is restricted to the actor's jurisdiction and records dismissal.
   */
  public function testJurisdictionAccessAndHide(): void {
    $this->controller->submitFlag($this->flagRequest());
    $staff = $this->staff('moderator');
    $this->container->get('current_user')->setAccount($staff);
    $nid = (int) $this->report->id();
    $request = new Request();
    $foreign = Group::create(['type' => 'jur', 'label' => 'Jurisdiction B']);
    $foreign->save();
    $foreign->addMember($staff, ['group_roles' => ['jur-tenant_admin']]);
    $this->assertSame(403, $this->controller->getFlagsForRequest($nid, $request)->getStatusCode());
    $this->assertSame(403, $this->controller->hideRequest($nid, $request)->getStatusCode());
    $this->assertTrue($this->reloadReport()->isPublished());

    $this->jurisdiction->addMember($staff, ['group_roles' => ['jur-tenant_admin']]);
    $this->assertSame(200, $this->controller->getFlagsForRequest($nid, $request)->getStatusCode());
    $this->assertSame(200, $this->controller->hideRequest($nid, $request)->getStatusCode());
    $this->assertFalse($this->reloadReport()->isPublished());
    $flags = $this->container->get('markaspot_moderation.service')->getFlagsForRequest($nid);
    $this->assertSame(1, $flags[0]['dismissed']);
    $this->assertSame((int) $staff->id(), $flags[0]['dismissed_by']);
    $this->assertGreaterThan(0, $flags[0]['dismissed_at']);
    $this->assertSame(0, $this->container->get('markaspot_moderation.service')
      ->getFlagCountForJurisdictions([(int) $this->jurisdiction->id()]));
  }

  /**
   * Creates a request with a distinct documentation-range source address.
   */
  private function flagRequest(string $ip = '192.0.2.1', array $values = []): Request {
    return Request::create('/api/moderation/flags', 'POST', [], [], [], [
      'REMOTE_ADDR' => $ip,
      'CONTENT_TYPE' => 'application/json',
    ], json_encode($values + [
      'service_request_id' => 'TEST-1',
      'reason' => 'spam',
      'details' => 'Repeated advertising',
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * Creates a real account with the moderation permission.
   */
  private function staff(string $name): User {
    $role = Role::create(['id' => 'flag_reviewer', 'label' => 'Flag reviewer']);
    $role->grantPermission('administer flags')->save();
    $user = User::create(['name' => $name, 'status' => 1, 'roles' => ['flag_reviewer']]);
    $user->save();
    return $user;
  }

  /**
   * Reloads the persisted publication state.
   */
  private function reloadReport(): Node {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$this->report->id()]);
    return $storage->load($this->report->id());
  }

}
