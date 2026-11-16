<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

use Drupal\Core\Config\ConfigImporter;
use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\eca\Entity\Eca;
use Drupal\field\Entity\FieldConfig;
use Drupal\markaspot_mail\Commands\StatusMailKeysCommands;
use Drupal\markaspot_mail\Service\StatusMailKeyAnalyzer;
use Drupal\markaspot_mail\Service\StatusMailKeyMigration;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\markaspot_mail\Unit\Service\StatusMailKeyAnalyzerTest;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Exercises command safety and actual ECA mail dispatch on saved reports.
 */
#[TestGroup('markaspot_mail')]
#[RunTestsInSeparateProcesses]
class StatusMailKeyMigrationTest extends StatusMailKernelTestBase {

  /**
   * Before/after count, recipient, language, subject and body are identical.
   */
  public function testRealSaveMailParityAndIsolation(): void {
    $before = $this->transitions();
    $this->assertSame([1, 1, 1, 1, 0, 0, 0], array_map('count', $before));
    $this->container->get('state')->set('system.maintenance_mode', TRUE);
    $result = $this->migration()->apply();
    $this->backups[] = $result['backup'];
    $this->assertSame('migrated now', $result['completed']);
    $this->assertSame($before, $this->transitions());
    $this->assertSame('already migrated', $this->migration()->apply()['result']);
    $this->assertTrue(Term::load(44)->get('field_notification_key')->isEmpty());
    // Same value and opted-out saves never trigger a status mail.
    $node = $this->report(3);
    $this->resetMail();
    $node->setTitle('A note without a status change')->save();
    $this->assertSame([], $this->mails());
    $node->set('field_notification', FALSE)->set('field_status', '5')->save();
    $this->assertSame([], $this->mails());
    // Clearing and setting the Drupal-editable term field governs sending.
    Term::load(5)->set('field_notification_key', '')->save();
    $node->set('field_notification', TRUE)->set('field_status', '3')->save();
    $node->set('field_status', '5')->save();
    $this->assertSame([], $this->mails());
    Term::load(5)->set('field_notification_key', 'status_closed')->save();
    $node->set('field_status', '3')->save();
    $node->set('field_status', '5')->save();
    $this->assertCount(1, $this->mails());
  }

  /**
   * The CLI dry run prints the neutral plan and changes nothing.
   */
  public function testCommandDryRunAndMissingFields(): void {
    $command = new StatusMailKeysCommands($this->migration());
    $output = new BufferedOutput();
    $command->setInput(new ArrayInput([]));
    $command->setOutput($output);
    $rows = $command->statusMailKeys()->getArrayCopy();
    $this->assertCount(4, $rows);
    $this->assertStringContainsString('ready to migrate', $output->fetch());
    file_put_contents(sys_get_temp_dir() . '/status-mail-keys-dry-run.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $this->assertTrue(Eca::load(StatusMailKeyAnalyzer::OLD_MODEL)->status());
    foreach ([4, 5, 30, 37] as $id) {
      $this->assertTrue(Term::load($id)->get('field_notification_key')->isEmpty());
    }
    FieldConfig::loadByName('taxonomy_term', 'service_status', 'field_notification_key')->delete();
    $this->container->get('state')->set('system.maintenance_mode', TRUE);
    $this->expectExceptionMessage('field_notification_key');
    $this->migration()->apply();
  }

  /**
   * A site without the report opt-in field must remain untouched.
   */
  public function testMissingOptInFieldAbortsBeforeWrite(): void {
    FieldConfig::loadByName('node', 'service_request', 'field_notification')->delete();
    $this->container->get('state')->set('system.maintenance_mode', TRUE);
    $this->assertRejected('field_notification');
  }

  /**
   * Maintenance, command lock, cron lock and queue leases are mandatory.
   */
  public function testMaintenanceAndLocks(): void {
    $this->assertRejected('Maintenance mode');
    $this->container->get('state')->set('system.maintenance_mode', TRUE);
    $db = $this->container->get('database');
    foreach ([StatusMailKeyMigration::LOCK, 'cron', 'queue_worker_test'] as $name) {
      $db->insert('semaphore')->fields(['name' => $name, 'value' => 'other-process', 'expire' => time() + 600])->execute();
      $this->assertRejected('lock');
      $db->delete('semaphore')->condition('name', $name)->execute();
    }
    $queue = $this->container->get('queue')->get('neutral_worker');
    $queue->createItem('neutral');
    $queue->claimItem();
    $this->assertRejected('queue item lease');
  }

  /**
   * A write failure is non-successful, with a usable before-state restore.
   */
  public function testFailureAndRestoreAndConfigImport(): void {
    $this->container->get('state')->set('system.maintenance_mode', TRUE);
    $before = $this->container->get('config.storage')->read('eca.eca.process_tunr6d6');
    $failing = new class (...$this->arguments()) extends StatusMailKeyMigration {

      /**
       * Fail after all key writes, immediately before disabling the model.
       */
      protected function disableOldModel(): void {
        throw new \RuntimeException('Simulated write failure');
      }

    };
    try {
      $failing->apply();
      $this->fail('A partial migration must throw.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Simulated write failure', $e->getMessage());
      $this->assertMatchesRegularExpression("/--restore='([^']+)'/", $e->getMessage());
      preg_match("/--restore='([^']+)'/", $e->getMessage(), $match);
      $this->assertStringEndsWith("--restore='" . $match[1] . "'", $e->getMessage());
      $backup = $match[1];
      $this->backups[] = $backup;
    }
    $this->assertSame('status_open', Term::load(4)->get('field_notification_key')->value);
    $this->assertTrue(Eca::load(StatusMailKeyAnalyzer::OLD_MODEL)->status());
    $this->assertSame($before, json_decode(file_get_contents($backup), TRUE)['models'][StatusMailKeyAnalyzer::OLD_MODEL]);
    $this->migration()->restore($backup);
    $this->assertSame($before, $this->container->get('config.storage')->read('eca.eca.process_tunr6d6'));
    $this->assertTrue(Term::load(4)->get('field_notification_key')->isEmpty());
    $result = $this->migration()->apply();
    $this->backups[] = $result['backup'];

    // Export migrated config, perturb its label, then use the real importer.
    $active = $this->container->get('config.storage');
    $export = new MemoryStorage();
    foreach ($active->listAll() as $name) {
      $export->write($name, $active->read($name));
    }
    Eca::load(StatusMailKeyAnalyzer::OLD_MODEL)->set('label', 'Temporary label')->save();
    $comparer = new StorageComparer($export, $active);
    $importer = new ConfigImporter($comparer->createChangelist(), $this->container->get('event_dispatcher'), $this->container->get('config.manager'), $this->container->get('lock'), $this->container->get('config.typed'), $this->container->get('module_handler'), $this->container->get('module_installer'), $this->container->get('theme_handler'), $this->container->get('string_translation'), $this->container->get('extension.list.module'), $this->container->get('extension.list.theme'));
    $importer->import();
    $this->assertSame('already migrated', $this->migration()->analyze()['result']);
    // apply's repeat path and real saves both see the rebuilt registration.
    $this->assertSame([1, 1, 1, 1, 0, 0, 0], array_map('count', $this->transitions()));
  }

  /**
   * Old model off plus missing keys is never reported as migrated.
   */
  public function testIncompleteAndNothingToMigrate(): void {
    Eca::load(StatusMailKeyAnalyzer::OLD_MODEL)->delete();
    Eca::load(StatusMailKeyAnalyzer::TERM_MODEL)->delete();
    $this->assertSame('nothing to migrate', $this->migration()->analyze()['result']);
    [$old, $term] = StatusMailKeyAnalyzerTest::models();
    Eca::create($term)->save();
    try {
      $this->migration()->analyze();
      $this->fail('A missing old model must be rejected.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('Old model is missing', $e->getMessage());
    }
    $old['status'] = FALSE;
    Eca::create($old)->save();
    $this->expectExceptionMessage('incomplete, not migrated');
    $this->migration()->analyze();
  }

  /**
   * The actual annotated command maps an apply exception to a nonzero exit.
   */
  public function testCommandFailureExitCode(): void {
    $this->container->get('state')->set('system.maintenance_mode', TRUE);
    $service = new class (...$this->arguments()) extends StatusMailKeyMigration {

      /**
       * Simulates a failure after the term writes.
       */
      protected function disableOldModel(): void {
        throw new \RuntimeException('Simulated command write failure');
      }

    };
    $command = new StatusMailKeysCommands($service);
    $command->setInput(new ArrayInput([]));
    $command->setOutput(new BufferedOutput());
    $factory = new AnnotatedCommandFactory();
    $application = new Application();
    $application->setAutoExit(FALSE);
    foreach ($factory->createCommandsFromClass($command) as $annotated) {
      $application->add($annotated);
    }
    $tester = new ApplicationTester($application);
    $exit = $tester->run(['command' => 'markaspot:status-mail-keys', '--apply' => TRUE]);
    $this->assertNotSame(0, $exit);
    $this->assertStringContainsString('Simulated command write failure', $tester->getDisplay());
    preg_match("/--restore='([^']+)'/", $tester->getDisplay(), $match);
    $this->assertNotEmpty($match);
    $this->backups[] = $match[1];
    $this->assertSame('status_open', Term::load(4)->get('field_notification_key')->value);
    $this->migration()->restore($match[1]);
    $this->assertTrue(Term::load(4)->get('field_notification_key')->isEmpty());
  }

}
