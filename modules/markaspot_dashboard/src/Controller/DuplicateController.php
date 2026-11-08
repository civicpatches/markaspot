<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_ai\Access\DuplicateMatchAccessPolicy;
use Drupal\markaspot_group\Service\StatusTermScope;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Controller for reviewing duplicate matches (confirm/reject).
 */
class DuplicateController extends ControllerBase {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The graceful jurisdiction scope for service status terms.
   */
  protected StatusTermScope $statusTermScope;

  /**
   * The shared duplicate access policy; NULL when markaspot_ai is absent.
   *
   * The same policy guards the markaspot_ai review form and API, so every
   * path that reads or writes match data applies identical rules.
   */
  protected ?DuplicateMatchAccessPolicy $duplicateAccessPolicy;

  /**
   * Constructs a DuplicateController object.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    AccountProxyInterface $current_user,
    TimeInterface $time,
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    StatusTermScope $status_term_scope,
    ?DuplicateMatchAccessPolicy $duplicate_access_policy = NULL,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->statusTermScope = $status_term_scope;
    $this->duplicateAccessPolicy = $duplicate_access_policy;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('markaspot_group.status_term_scope'),
      $container->has('markaspot_ai.duplicate_match_access')
        ? $container->get('markaspot_ai.duplicate_match_access')
        : NULL,
    );
  }

  /**
   * Review a duplicate match (confirm or reject).
   *
   * When confirmed, this will:
   * - Add a status note to the duplicate request
   * - Set the duplicate request's status to Closed
   * - Update the match status.
   *
   * @param int $match_id
   *   The duplicate match ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object with JSON body containing 'status'.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with success/error.
   */
  public function reviewMatch(int $match_id, Request $request): JsonResponse {
    // Guard first: avoids a DatabaseExceptionWrapper on vision-only tenants
    // where markaspot_ai_duplicate_matches does not exist.
    if (!$this->isDuplicateDetectionEnabled()) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    // Load the match record.
    $match = $this->database->select('markaspot_ai_duplicate_matches', 'm')
      ->fields('m')
      ->condition('id', $match_id)
      ->execute()
      ->fetchObject();

    // Both reports must be visible and updatable for the reviewer, inside
    // its tenant scope and opt-in. A missing match, a deleted report and a
    // foreign match all answer the same, so the API is no existence oracle.
    if (!$match || !$this->duplicateAccessPolicy
      || !$this->duplicateAccessPolicy->matchAccess([
        'source_nid' => $match->source_nid,
        'match_nid' => $match->match_nid,
      ], 'update', $this->currentUser)->isAllowed()) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Access denied.',
      ], 403);
    }

    // Parse request body.
    $data = json_decode($request->getContent(), TRUE);
    if (!isset($data['status']) || !in_array($data['status'], ['confirmed', 'rejected'])) {
      throw new BadRequestHttpException('Invalid status. Must be "confirmed" or "rejected".');
    }

    $new_status = $data['status'];
    $closed_tid = NULL;

    $node_storage = $this->entityTypeManager->getStorage('node');
    $duplicate_node = $node_storage->load($match->match_nid);
    $source_node = $node_storage->load($match->source_nid);

    if ($new_status === 'confirmed') {
      $closed_tid = $this->resolveClosedStatusTid($duplicate_node);
      if ($closed_tid === NULL) {
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'No selectable closed status is configured for this jurisdiction.',
        ], 409);
      }
    }

    // Update the match status.
    $this->database->update('markaspot_ai_duplicate_matches')
      ->fields([
        'status' => $new_status,
        'reviewed_by' => $this->currentUser->id(),
        'reviewed_at' => $this->time->getRequestTime(),
      ])
      ->condition('id', $match_id)
      ->execute();

    // If confirmed, auto-close the duplicate and add status note.
    if ($new_status === 'confirmed') {
      $this->confirmDuplicate($duplicate_node, $source_node, $closed_tid);
    }

    return new JsonResponse([
      'success' => TRUE,
      'match_id' => $match_id,
      'status' => $new_status,
    ]);
  }

  /**
   * Checks whether the AI duplicate feature is globally available.
   */
  protected function isDuplicateDetectionEnabled(): bool {
    if (!$this->duplicateAccessPolicy || !$this->moduleHandler->moduleExists('markaspot_ai')) {
      return FALSE;
    }
    $this->moduleHandler->loadInclude('markaspot_ai', 'module');

    return $this->duplicateAccessPolicy->isDuplicateDetectionEnabled();
  }

  /**
   * Resolves a service request node's jurisdiction ID via the shared policy.
   */
  protected function getJurisdictionIdForNode(NodeInterface $node): ?int {
    return $this->duplicateAccessPolicy?->jurisdictionIdForNode($node);
  }

  /**
   * Confirm a duplicate: add status note and close the request.
   *
   * @param \Drupal\node\NodeInterface $duplicate_node
   *   The duplicate service request node.
   * @param \Drupal\node\NodeInterface $source_node
   *   The original/source service request node.
   * @param int $closed_tid
   *   Selected closed status term ID.
   */
  protected function confirmDuplicate(
    NodeInterface $duplicate_node,
    NodeInterface $source_node,
    int $closed_tid,
  ): void {
    // Get the language of the duplicate request.
    $langcode = $duplicate_node->language()->getId();

    // Build the status note message based on language.
    $source_request_id = $this->getServiceRequestId($source_node);
    if ($langcode === 'de') {
      $note_text = "Als Duplikat von #{$source_request_id} markiert";
    }
    else {
      $note_text = "Marked as duplicate of #{$source_request_id}";
    }

    // Add the status note.
    $this->addStatusNote($duplicate_node, $note_text);

    if ($duplicate_node->hasField('field_status')) {
      $duplicate_node->set('field_status', ['target_id' => $closed_tid]);
    }

    // Save the node.
    $duplicate_node->save();
  }

  /**
   * Add a status note paragraph to a service request node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param string $note_text
   *   The status note text to add.
   */
  protected function addStatusNote(NodeInterface $node, string $note_text): void {
    // Get the current status to associate with this note.
    $current_status = $node->hasField('field_status') ? $node->get('field_status')->target_id : NULL;

    // Convert line breaks to <br> tags for proper display.
    $note_text_html = nl2br($note_text, FALSE);

    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $status_note_paragraph = $paragraph_storage->create([
      'type' => 'status',
      'field_status_note' => [
        'value' => $note_text_html,
        'format' => 'basic_html',
      ],
      'field_status_term' => [
        'target_id' => $current_status,
      ],
    ]);
    $status_note_paragraph->save();

    // Add to field_status_notes.
    $notes = $node->get('field_status_notes')->getValue();
    $notes[] = [
      'target_id' => $status_note_paragraph->id(),
      'target_revision_id' => $status_note_paragraph->getRevisionId(),
    ];
    $node->set('field_status_notes', $notes);
  }

  /**
   * Resolves the "closed" status term ID for a node's jurisdiction.
   *
   * Status terms are jurisdiction-specific: each jurisdiction has its own
   * set of terms with field_open311_mapping indicating open/closed/initial.
   * The controller's existing effective node resolver keeps this lookup
   * aligned with duplicate access checks.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The closed status term ID, or NULL if not found.
   */
  protected function resolveClosedStatusTid(NodeInterface $node): ?int {
    $jurisdictionId = $this->getJurisdictionIdForNode($node);

    // Build query for "closed" status terms in this jurisdiction.
    $properties = [
      'vid' => 'service_status',
      'status' => 1,
      'field_open311_mapping' => 'closed',
    ];
    $terms = $this->statusTermScope
      ->loadByProperties($properties, $jurisdictionId);

    if (!empty($terms)) {
      $term = reset($terms);
      return (int) $term->id();
    }

    return NULL;
  }

  /**
   * Get the service_request_id from a node's title.
   *
   * Expected format: "#123-2026 Some Title"
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return string
   *   The service request ID (e.g., "123-2026") or the nid as fallback.
   */
  protected function getServiceRequestId(NodeInterface $node): string {
    $title = $node->getTitle();
    if (preg_match('/#(\d+-\d+)/', $title, $matches)) {
      return $matches[1];
    }
    return (string) $node->id();
  }

}
