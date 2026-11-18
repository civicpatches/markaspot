<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_publisher\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\markaspot_privacy\Form\GDPRForm;
use Drupal\markaspot_privacy\ReportEmailErasure;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

require_once __DIR__ . '/PublisherKernelTest.php';

/**
 * Exercises unpublish holds through entity saves, candidate queries and cron.
 *
 * @group markaspot_publisher
 */
#[RunTestsInSeparateProcesses]
final class UnpublishHoldKernelTest extends PublisherKernelTest {

  /**
   * The report for which the test inserts a hold during the worker's save.
   */
  private ?string $holdDuringSave = NULL;

  /**
   * Only publication transitions change a report's hold until deletion.
   */
  public function testHoldLifecycle(): void {
    $report = $this->report(31, ['status' => 1]);
    $nid = (string) $report->id();
    $report->setUnpublished()->save();
    $expected = [
      'time' => $this->container->get('datetime.time')->getRequestTime(),
    ];
    $this->assertSame($expected, $this->holds()->get($nid));

    $report->setTitle('Still offline')->save();
    $this->assertSame($expected, $this->holds()->get($nid));
    $report->setPublished()->save();
    $this->assertFalse($this->holds()->has($nid));
    $report->setUnpublished()->save();
    $this->assertSame($expected, $this->holds()->get($nid));
    $report->delete();
    $this->assertFalse($this->holds()->has($nid));

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $page = $this->report(31, ['type' => 'page', 'status' => 1]);
    $page->setUnpublished()->save();
    $this->assertFalse($this->holds()->has((string) $page->id()));
  }

  /**
   * Withdrawing a never-published report vetoes selection and queued work.
   */
  public function testCitizenWithdrawalBeforePublication(): void {
    $this->enableModules(['markaspot_privacy']);
    FieldStorageConfig::create([
      'field_name' => 'field_e_mail',
      'entity_type' => 'node',
      'type' => 'email',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_e_mail',
      'entity_type' => 'node',
      'bundle' => 'service_request',
    ])->save();
    $report = $this->report(31, ['field_e_mail' => 'citizen@example.invalid']);
    $nid = (string) $report->id();
    markaspot_publisher_cron();
    $queue = $this->container->get('queue')->get('markaspot_publisher_queue_worker');
    $item = $queue->claimItem();
    $this->assertSame($nid, (string) $item->data['nid']);

    $form = [];
    $state = (new FormState())->setValue('uuid', $report->uuid());
    GDPRForm::create($this->container)->submitForm($form, $state);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $report = $storage->loadUnchanged($nid);
    $this->assertSame(ReportEmailErasure::ERASED_EMAIL, $report->get('field_e_mail')->value);
    // Move the edit past the threshold so only the withdrawal can exclude it.
    $report->setChangedTime($report->getCreatedTime())->save();
    $this->assertArrayNotHasKey($nid, $this->container->get('markaspot_publisher.publisher')->load());
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('markaspot_publisher_queue_worker');
    $worker->processItem($item->data);
    $queue->deleteItem($item);
    $this->assertFalse($storage->loadUnchanged($nid)->isPublished());
    $this->assertSame(['time' => $this->container->get('datetime.time')->getRequestTime()], $this->holds()->get($nid));
  }

  /**
   * Archive notifications can repeat, or refer to a still-published report.
   */
  public function testRepeatedAnonymization(): void {
    $handler = $this->container->get('module_handler');
    $report = $this->report(31, ['status' => 1]);
    $nid = (string) $report->id();
    $handler->invokeAll('markaspot_report_anonymized', [$report]);
    $this->assertFalse($this->holds()->has($nid));

    $report->setUnpublished()->save();
    $this->holds()->set($nid, ['time' => 123]);
    $handler->invokeAll('markaspot_report_anonymized', [$report]);
    $this->assertSame(['time' => 123], $this->holds()->get($nid));
  }

  /**
   * Each category shares a single read, even when the hold collection is empty.
   */
  public function testHoldCollectionReadOnceAcrossCategories(): void {
    $second = Term::create(['vid' => 'service_category', 'name' => 'Park']);
    $second->save();
    $this->config('markaspot_publisher.settings')
      ->set('days', [$this->category->id() => 30, $second->id() => 30])
      ->save();
    $first_report = $this->report(31);
    $second_report = $this->report(31, ['field_category' => $second->id()]);

    $collection = $this->createMock(KeyValueStoreInterface::class);
    $collection->expects($this->once())->method('getAll')->willReturn([]);
    $factory = $this->createMock(KeyValueFactoryInterface::class);
    $factory->method('get')->willReturnCallback(
      fn(string $name) => $name === 'markaspot_publisher.unpublish_hold' ? $collection : $this->keyValue->get($name)
    );
    $this->container->set('keyvalue', $factory);
    $candidates = $this->container->get('markaspot_publisher.publisher')->load();
    $this->assertEqualsCanonicalizing([$first_report->id(), $second_report->id()], array_keys($candidates));
  }

  /**
   * A draft can neither hold a published report nor release a held report.
   */
  public function testNonDefaultRevisions(): void {
    $report = $this->report(31, ['status' => 1]);
    $nid = (string) $report->id();
    $report->setNewRevision();
    $report->isDefaultRevision(FALSE);
    $report->setUnpublished()->save();
    $this->assertFalse($this->holds()->has($nid));

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $report = $storage->loadUnchanged($nid);
    $this->assertTrue($report->isPublished());
    $report->setUnpublished()->save();
    $hold = $this->holds()->get($nid);
    $this->assertNotNull($hold);

    $report->setNewRevision();
    $report->isDefaultRevision(FALSE);
    $report->setPublished()->save();
    $this->assertSame($hold, $this->holds()->get($nid));
    $this->assertFalse($storage->loadUnchanged($nid)->isPublished());
  }

  /**
   * A save through a translation still compares both default-language states.
   */
  public function testDefaultTranslationDecides(): void {
    $this->enableModules(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();
    $report = $this->report(31, ['langcode' => 'en', 'status' => 1]);
    $nid = (string) $report->id();
    $translation = $report->addTranslation('de', ['title' => 'Meldung', 'status' => 1]);
    $translation->save();
    $translation->setUnpublished()->save();
    $this->assertFalse($this->holds()->has($nid));

    $report->setUnpublished();
    $translation->save();
    $this->assertTrue($this->holds()->has($nid));
    $translation->setPublished()->save();
    $this->assertTrue($this->holds()->has($nid));

    $report->setPublished();
    $translation->save();
    $this->assertFalse($this->holds()->has($nid));
  }

  /**
   * The worker sees holds written after cron selected a never-published report.
   */
  public function testHoldAfterEnqueueing(): void {
    $report = $this->report(31);
    $nid = (string) $report->id();
    markaspot_publisher_cron();
    $queue = $this->container->get('queue')->get('markaspot_publisher_queue_worker');
    $item = $queue->claimItem();
    $this->assertSame($nid, (string) $item->data['nid']);
    $this->assertFalse($this->holds()->has($nid));

    $report->setPublished()->save();
    $report->setUnpublished()->save();
    $hold = $this->holds()->get($nid);
    $this->assertNotNull($hold);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('markaspot_publisher_queue_worker');
    $worker->processItem($item->data);
    $queue->deleteItem($item);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertFalse($storage->loadUnchanged($nid)->isPublished());
    $this->assertSame($hold, $this->holds()->get($nid));
  }

  /**
   * The worker reads a hold written outside the request's candidate cache.
   */
  public function testWorkerReadsHoldFromStorage(): void {
    $report = $this->report(31);
    $nid = (string) $report->id();
    $this->assertArrayHasKey($nid, $this->container->get('markaspot_publisher.publisher')->load());
    // A withdrawal in another request writes to the same durable collection.
    $this->holds()->set($nid, ['time' => 123]);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('markaspot_publisher_queue_worker');
    $worker->processItem(['nid' => $nid]);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $this->assertFalse($storage->loadUnchanged($nid)->isPublished());
  }

  /**
   * A worker publication leaves both an empty store and a late hold alone.
   */
  public function testWorkerDoesNotChangeHolds(): void {
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('markaspot_publisher_queue_worker');
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $report = $this->report(31);
    $nid = (string) $report->id();
    $report->setTitle('Waiting for publication')->setChangedTime($report->getCreatedTime() + 1)->save();
    $this->assertFalse($this->holds()->has($nid));
    $this->assertArrayHasKey($nid, $this->container->get('markaspot_publisher.publisher')->load());
    $worker->processItem(['nid' => $nid]);
    $this->assertTrue($storage->loadUnchanged($nid)->isPublished());
    $this->assertSame([], $this->holds()->getAll());

    $late = $this->report(31);
    $this->holdDuringSave = (string) $late->id();
    $worker->processItem(['nid' => $late->id()]);
    $this->assertTrue($storage->loadUnchanged($late->id())->isPublished());
    $this->assertSame(['time' => 123], $this->holds()->get($this->holdDuringSave));
  }

  /**
   * Places a hold after the final check, before the update hook can release it.
   */
  #[Hook('node_presave')]
  public function holdDuringWorkerSave(NodeInterface $node): void {
    if ($this->holdDuringSave === (string) $node->id()) {
      $this->holds()->set($this->holdDuringSave, ['time' => 123]);
    }
  }

  /**
   * Held reports past the time threshold do not occupy the category batch.
   */
  public function testHoldsBeforeBatchLimit(): void {
    for ($i = 0; $i < 21; $i++) {
      $report = $this->report(31, ['status' => 1]);
      $report->setUnpublished()->setChangedTime($report->getCreatedTime() + 1)->save();
    }
    $eligible = [];
    for ($i = 0; $i < 20; $i++) {
      $eligible[] = (int) $this->report(31)->id();
    }
    $this->assertCount(21, $this->holds()->getAll());
    $candidates = $this->container->get('markaspot_publisher.publisher')->load();
    $this->assertEqualsCanonicalizing($eligible, array_keys($candidates));
  }

  /**
   * Returns the durable hold collection directly for storage assertions.
   */
  private function holds(): KeyValueStoreInterface {
    return $this->container->get('keyvalue')->get('markaspot_publisher.unpublish_hold');
  }

}
