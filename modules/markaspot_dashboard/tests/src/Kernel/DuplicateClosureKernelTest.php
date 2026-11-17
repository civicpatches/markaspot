<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_dashboard\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\eca\Entity\Eca;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_ai\Access\DuplicateMatchAccessPolicy;
use Drupal\markaspot_dashboard\Controller\DuplicateController;
use Drupal\markaspot_mail\Service\DuplicateNotification;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\markaspot_mail\Kernel\StatusMailKernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Real node saves prove the duplicate note and key-driven mail contract.
 */
#[Group('markaspot_dashboard')]
#[RunTestsInSeparateProcesses]
final class DuplicateClosureKernelTest extends StatusMailKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    static::$modules[] = 'markaspot_token';
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->createField('node', 'service_request', 'request_id', 'string');
    $this->createField('taxonomy_term', 'service_status', 'field_open311_mapping', 'string');
    Term::load(5)->set('field_open311_mapping', 'closed')->set('field_notification_key', 'status_closed')->save();
    Eca::load('process_tunr6d6')->disable()->save();
    require_once dirname(__DIR__, 4) . '/markaspot_ai/markaspot_ai.install';
    $this->container->get('database')->schema()->createTable('markaspot_ai_duplicate_matches', markaspot_ai_schema()['markaspot_ai_duplicate_matches']);
    $this->container->get('module_handler')->loadInclude('markaspot_mail', 'install');
    markaspot_mail_update_10021();
  }

  /**
   * Both entry directions keep the older report and disclose only public IDs.
   */
  #[DataProvider('directions')]
  public function testClosure(bool $reverse, bool $published, string $langcode, bool $consent, string $notificationKey = 'status_closed'): void {
    Term::load(5)->set('field_notification_key', $notificationKey)->save();
    $expects_mail = $consent && $notificationKey !== '';
    $kept = $this->report(4);
    $kept->set('request_id', 'KEPT-42')->set('status', $published)->save();
    $closed = $this->report(4);
    $closed->set('request_id', 'CLOSED-43')->set('langcode', $langcode)->set('field_notification', $consent)->save();
    $match = $this->container->get('database')->insert('markaspot_ai_duplicate_matches')->fields([
      'source_nid' => $reverse ? $closed->id() : $kept->id(),
      'match_nid' => $reverse ? $kept->id() : $closed->id(),
      'similarity_score' => 0.98,
      'status' => 'pending',
      'created' => time(),
    ])->execute();
    $this->config('markaspot_mail.settings')->set('platform.frontend_base_url', 'https://reports.example.test')->save();
    $this->resetMail();
    $request = Request::create('/', 'POST', [], [], [], [], '{"status":"confirmed"}');
    $response = $this->controller()->reviewMatch((int) $match, $request);
    $this->assertSame(200, $response->getStatusCode());
    $data = json_decode($response->getContent(), TRUE);
    $this->assertSame(['nid' => (int) $kept->id(), 'request_id' => 'KEPT-42'], $data['kept']);
    $this->assertSame(['nid' => (int) $closed->id(), 'request_id' => 'CLOSED-43'], $data['closed']);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $saved = Node::load($closed->id());
    $this->assertSame('5', $saved->get('field_status')->target_id);
    $this->assertSame('4', Node::load($kept->id())->get('field_status')->target_id);
    $note = $saved->get('field_status_notes')->entity;
    $this->assertSame($langcode, $note->language()->getId());
    $this->assertSame('5', $note->get('field_status_term')->target_id);
    $body = $note->get('field_status_note')->value;
    $this->assertStringContainsString($langcode === 'de' ? 'Als Dublette' : 'Closed as a duplicate', $body);
    $published ? $this->assertStringContainsString('KEPT-42', $body) : $this->assertStringNotContainsString('KEPT-42', $body);
    $mails = $this->mails();
    $this->assertCount($expects_mail ? 1 : 0, $mails);
    if ($expects_mail) {
      $this->assertSame('markaspot_mail_notification_status_duplicate', $mails[0]['id']);
      $this->assertSame($langcode, $mails[0]['langcode']);
      $this->assertSame('reporter@example.test', $mails[0]['to']);
      $this->assertStringNotContainsString('resolved', $mails[0]['subject']);
      $text = is_array($mails[0]['body']) ? implode('\n', $mails[0]['body']) : $mails[0]['body'];
      $published ? $this->assertStringContainsString('KEPT-42', $text) : $this->assertStringNotContainsString('KEPT-42', $text);
    }
    $this->assertNull(DuplicateNotification::keptReport($saved));
    // An HTTP retry is successful but creates neither a second note nor mail.
    $this->controller()->reviewMatch((int) $match, $request);
    $this->assertCount($expects_mail ? 1 : 0, $this->mails());
    $storage->resetCache();
    $this->assertCount(1, Node::load($closed->id())->get('field_status_notes'));
    // The override does not leak into an ordinary later status change.
    $this->resetMail();
    $saved->set('field_status', '4')->save();
    $saved->set('field_status', '5')->save();
    $this->assertCount($expects_mail ? 1 : 0, $this->mails());
    if ($expects_mail) {
      $this->assertSame('markaspot_mail_notification_status_closed', $this->mails()[0]['id']);
    }
  }

  /**
   * Direction, visibility, language and consent cases.
   */
  public static function directions(): array {
    return [
      'older entry' => [FALSE, TRUE, 'en', TRUE],
      'newer entry' => [TRUE, TRUE, 'de', TRUE],
      'unpublished kept report' => [FALSE, FALSE, 'de', TRUE],
      'opted out' => [FALSE, TRUE, 'en', FALSE],
      'closed status without a mail key' => [FALSE, TRUE, 'en', TRUE, ''],
    ];
  }

  /**
   * Updates add only missing keys in the default and translated configs.
   */
  public function testUpdatePreservesCustomTexts(): void {
    $config = $this->config('markaspot_mail.texts');
    $config->set('status_duplicate.subject', 'Custom duplicate subject')->save();
    $override = $this->container->get('language_manager')->getLanguageConfigOverride('de', 'markaspot_mail.texts');
    $override->set('status_duplicate', ['subject' => 'Eigener Text'])->save();
    markaspot_mail_update_10021();
    $this->assertSame('Custom duplicate subject', $config->get('status_duplicate.subject'));
    $this->assertSame(['subject' => 'Eigener Text'], $override->get('status_duplicate'));
    $config->clear('status_duplicate')->save();
    $override->clear('status_duplicate')->save();
    markaspot_mail_update_10021();
    $this->assertNotEmpty($this->config('markaspot_mail.texts')->get('status_duplicate.subject'));
    $fresh = $this->container->get('language_manager')->getLanguageConfigOverride('de', 'markaspot_mail.texts');
    $this->assertNotEmpty($fresh->get('status_duplicate.subject'));
  }

  /**
   * Measures the unchanged legacy automation: it still sends its old key.
   */
  public function testLegacyAutomationRemainsUnchanged(): void {
    Eca::load('process_notify_status_term')->disable()->save();
    Eca::load('process_tunr6d6')->enable()->save();
    $kept = $this->report(4);
    $closed = $this->report(4);
    $this->resetMail();
    $closed->set('field_status', '5');
    DuplicateNotification::save($closed, $kept);
    $this->assertCount(1, $this->mails());
    $this->assertSame('markaspot_mail_notification_status_closed', $this->mails()[0]['id']);
  }

  /**
   * A failed closure stays pending and a retry can still complete it.
   */
  public function testFailedSaveAndContendingReview(): void {
    $kept = $this->report(4);
    $closed = $this->report(4);
    $db = $this->container->get('database');
    $match = $db->insert('markaspot_ai_duplicate_matches')->fields([
      'source_nid' => $kept->id(), 'match_nid' => $closed->id(),
      'similarity_score' => 0.98, 'status' => 'pending', 'created' => time(),
    ])->execute();
    $request = Request::create('/', 'POST', [], [], [], [], '{"status":"confirmed"}');
    $this->resetMail();
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->method('acquire')->willReturn(FALSE);
    $this->assertSame(409, $this->controller(lock: $lock)->reviewMatch((int) $match, $request)->getStatusCode());
    $rejection = Request::create('/', 'POST', [], [], [], [], '{"status":"rejected"}');
    $this->assertSame(409, $this->controller(lock: $lock)->reviewMatch((int) $match, $rejection)->getStatusCode());
    try {
      $this->controller(TRUE)->reviewMatch((int) $match, $request);
      $this->fail('The failed closure must throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Simulated closure failure', $e->getMessage());
    }
    $this->assertSame('pending', $db->select('markaspot_ai_duplicate_matches', 'm')->fields('m', ['status'])->condition('id', $match)->execute()->fetchField());
    $this->assertSame('4', Node::load($closed->id())->get('field_status')->target_id);
    $this->assertCount(0, Node::load($closed->id())->get('field_status_notes'));
    $this->assertSame([], $this->mails());
    $this->assertSame(200, $this->controller()->reviewMatch((int) $match, $request)->getStatusCode());
    $this->assertCount(1, $this->mails());
  }

  /**
   * Uses the production controller with an allowed, already-tested policy.
   */
  private function controller(bool $fail = FALSE, ?LockBackendInterface $lock = NULL): DuplicateController {
    $policy = $this->createMock(DuplicateMatchAccessPolicy::class);
    $policy->method('isDuplicateDetectionEnabled')->willReturn(TRUE);
    $policy->method('matchAccess')->willReturn(AccessResult::allowed());
    $policy->method('jurisdictionIdForNode')->willReturn(1);
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturn(TRUE);
    $controller = new class (
      $this->container->get('database'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('datetime.time'),
      $this->container->get('config.factory'),
      $modules,
      $this->container->get('markaspot_group.status_term_scope'),
      $policy,
      $this->container->get('markaspot_mail.text_resolver'),
      $lock ?? $this->container->get('lock'),
    ) extends DuplicateController {

      /**
       * Whether to fail after creating the note.
       */
      public bool $failOnNote = FALSE;

      /**
       * Verifies the match decision precedes the report's save side effects.
       */
      protected function confirmDuplicate(NodeInterface $duplicate_node, NodeInterface $source_node, int $closed_tid): void {
        $status = $this->database->select('markaspot_ai_duplicate_matches', 'm')
          ->fields('m', ['status'])->execute()->fetchField();
        if ($status !== 'confirmed') {
          throw new \LogicException('The match must be confirmed before saving the report.');
        }
        parent::confirmDuplicate($duplicate_node, $source_node, $closed_tid);
      }

      /**
       * Simulates a report-save failure after the paragraph write.
       */
      protected function addStatusNote(NodeInterface $node, string $note_text): void {
        parent::addStatusNote($node, $note_text);
        if ($this->failOnNote) {
          throw new \RuntimeException('Simulated closure failure');
        }
      }

    };
    $controller->failOnNote = $fail;
    return $controller;
  }

}
