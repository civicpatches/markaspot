<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_moderation\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_moderation\Service\PublicationHold;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\service_request\RevisionLog;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once dirname(__DIR__, 4) . '/markaspot_group/src/Service/TenantAdminHelper.php';
require_once dirname(__DIR__, 4) . '/service_request/src/RevisionLog.php';
require_once dirname(__DIR__, 3) . '/markaspot_moderation.post_update.php';

/**
 * Legacy prose hides become events the publication hold recognises.
 *
 * @group markaspot_moderation
 */
#[RunTestsInSeparateProcesses]
final class LegacyHideEventsPostUpdateKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'entity', 'file',
    'language', 'locale', 'flexible_permissions', 'group', 'gnode',
    'markaspot_request_id', 'markaspot_moderation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group', 'group_relationship', 'group_config_wrapper'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installSchema('node', ['node_access']);
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location', 'locale_file']);
    $this->installSchema('markaspot_moderation', ['markaspot_moderation_flags']);
    $this->installConfig(['system', 'user', 'node', 'group', 'markaspot_moderation']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
  }

  /**
   * Exact legacy messages and stored translations convert; nothing else does.
   */
  public function testLegacyHideMessagesBecomeHoldEvents(): void {
    $database = $this->container->get('database');
    $lid = (int) $database->insert('locales_source')->fields([
      'source' => 'Automatically hidden: flag threshold (@count) reached.',
      'context' => '',
      'version' => 'none',
    ])->execute();
    $database->insert('locales_target')->fields([
      'lid' => $lid,
      'language' => 'de',
      'translation' => 'Automatisch ausgeblendet: Schwelle (@count) erreicht.',
      'customized' => 1,
    ])->execute();

    $manual = $this->report('Hidden by moderator due to content flags.');
    $automatic = $this->report('Automatically hidden: flag threshold (5) reached.');
    $translated = $this->report('Automatisch ausgeblendet: Schwelle (4) erreicht.');
    $similar = $this->report('Hidden by moderator due to content flags. Reviewed again later.');
    $unrelated = $this->report('Edited by staff.');
    $ids = [$manual, $automatic, $translated, $similar, $unrelated];

    $hold = $this->container->get(PublicationHold::class);
    $this->assertSame([], $hold->heldIds($ids), 'Prose alone must not hold before the conversion.');

    $this->assertStringContainsString('Converted 3 ', $this->runPostUpdate());

    $this->assertEqualsCanonicalizing([$manual, $automatic, $translated], $hold->heldIds($ids));
    $decoded = RevisionLog::decode($this->log($automatic));
    $this->assertSame(['moderation_auto_hidden'], $decoded['events']);
    $this->assertSame('Automatically hidden: flag threshold (5) reached.', $decoded['message']);
    $this->assertSame(['moderation_hidden'], RevisionLog::decode($this->log($manual))['events']);
    $this->assertSame(['moderation_auto_hidden'], RevisionLog::decode($this->log($translated))['events']);
    $this->assertSame('Hidden by moderator due to content flags. Reviewed again later.', $this->log($similar));
    $this->assertSame('Edited by staff.', $this->log($unrelated));

    // A second run finds nothing left to convert.
    $this->assertStringContainsString('Converted 0 ', $this->runPostUpdate());
  }

  /**
   * Creates an unpublished report whose only revision carries the log.
   */
  private function report(string $log): int {
    static $sequence = 0;
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'request_id' => 'LEGACY-' . ++$sequence,
      'status' => 0,
      'revision_log' => $log,
    ]);
    $node->save();
    return (int) $node->id();
  }

  /**
   * Reads the stored revision log of a report's current revision.
   */
  private function log(int $nid): string {
    return (string) $this->container->get('database')->select('node_revision', 'r')
      ->fields('r', ['revision_log'])
      ->condition('r.nid', $nid)
      ->execute()->fetchField();
  }

  /**
   * Runs the post update to completion and returns its final message.
   */
  private function runPostUpdate(): string {
    $sandbox = [];
    do {
      $message = markaspot_moderation_post_update_legacy_hide_events($sandbox);
    } while (($sandbox['#finished'] ?? 1) < 1);
    return $message;
  }

}
