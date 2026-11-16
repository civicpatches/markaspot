<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\StaffCapability;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authorizes intake within a validated workspace without fabricating consent.
 */
final class StaffIntakeTicket {

  public const HEADER = 'X-Markaspot-Staff-Intake';
  public const ROUTE = 'jsonapi.node--service_request.collection.post';

  /**
   * Constructs the workspace-bound intake service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
    private readonly StaffCapability $capability,
    private readonly StaffIntakeSession $session,
    private readonly StaffIntakeTicketStore $store,
    private readonly StaffIntakeContext $context,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Resolves every edge, rejecting missing parents, wrong types and cycles.
   */
  private function root(int $id): ?GroupInterface {
    $seen = [];
    $type = $this->configFactory->get('markaspot_open311.settings')->get('jurisdiction_group_type') ?: 'jur';
    while ($id > 0 && !isset($seen[$id]) && count($seen) < 50) {
      $seen[$id] = TRUE;
      $group = $this->entityTypeManager->getStorage('group')->load($id);
      if (!$group instanceof GroupInterface || $group->bundle() !== $type) {
        return NULL;
      }
      if (!$group->hasField('field_parent_jurisdiction') || $group->get('field_parent_jurisdiction')->isEmpty()) {
        return $group;
      }
      $parents = $group->get('field_parent_jurisdiction')->getValue();
      if (count($parents) !== 1) {
        return NULL;
      }
      $id = (int) ($parents[0]['target_id'] ?? 0);
    }
    return NULL;
  }

  /**
   * Issues only for a root workspace and a qualified cookie session.
   */
  public function issue(Request $request, int $workspace): ?string {
    $sessionHash = $this->session->hash($request);
    $root = $this->root($workspace);
    if ($sessionHash === NULL || !$root || (int) $root->id() !== $workspace
      || !$this->capability->canIntakeInWorkspace($this->currentUser, $root)) {
      return NULL;
    }
    return $this->store->issue((int) $this->currentUser->id(), $sessionHash, $workspace);
  }

  /**
   * Called only after complete Core validation of the primary create object.
   */
  public function bindValidatedPrimary(Request $request, NodeInterface $node): void {
    if ($request->attributes->get('_route') !== self::ROUTE
      || !$request->isMethod('POST') || $node->bundle() !== 'service_request' || !$node->isNew()) {
      return;
    }
    $ticket = $request->headers->get(self::HEADER, '');
    $sessionHash = $this->session->hash($request);
    if ($ticket === '' || $sessionHash === NULL) {
      return;
    }
    $uid = (int) $this->currentUser->id();
    try {
      $record = $this->store->find($ticket, $uid, $sessionHash);
    }
    catch (\Throwable) {
      $this->logger->warning('Staff intake ticket storage failed; consent remains required.');
      return;
    }
    if ($record === NULL) {
      return;
    }
    $workspace = (int) $record['workspace_id'];
    $root = $this->root($workspace);
    if (!$root || (int) $root->id() !== $workspace
      || !$this->capability->canIntakeInWorkspace($this->currentUser, $root)
      || !$node->hasField('field_jurisdiction') || $node->get('field_jurisdiction')->isEmpty()) {
      return;
    }
    foreach ($node->get('field_jurisdiction')->getValue() as $item) {
      $targetRoot = $this->root((int) ($item['target_id'] ?? 0));
      if (!$targetRoot || (int) $targetRoot->id() !== $workspace) {
        return;
      }
    }
    try {
      $consumed = $this->store->consume($ticket, $uid, $sessionHash);
    }
    catch (\Throwable) {
      $this->logger->warning('Staff intake ticket storage failed; consent remains required.');
      return;
    }
    if ($consumed) {
      $this->context->bind($request, $node);
    }
  }

}
