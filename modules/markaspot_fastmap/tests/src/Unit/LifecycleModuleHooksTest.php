<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\WorkspaceLifecycleGate;
use Drupal\markaspot_fastmap\Service\WorkspaceNotOpenException;
use Drupal\markaspot_fastmap\Service\WorkspaceProvisioningServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that the module's hooks hand the lifecycle decisions to the gate.
 *
 * Runs the hook functions of this checkout's module file; the decisions
 * themselves are covered by WorkspaceLifecycleGateTest.
 *
 * @group markaspot_fastmap
 */
class LifecycleModuleHooksTest extends UnitTestCase {

  /**
   * The gate double.
   */
  private WorkspaceLifecycleGate $gate;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/markaspot_fastmap.module';
    $this->gate = $this->createMock(WorkspaceLifecycleGate::class);
    $container = new ContainerBuilder();
    $container->set('markaspot_fastmap.workspace_lifecycle_gate', $this->gate);
    $container->set('current_user', $this->createMock(AccountProxyInterface::class));
    \Drupal::setContainer($container);
  }

  /**
   * The hook functions come from this checkout.
   */
  public function testHookFunctionsComeFromThisCheckout(): void {
    $module = realpath(dirname(__DIR__, 3) . '/markaspot_fastmap.module');
    $functions = [
      'markaspot_fastmap_node_presave',
      'markaspot_fastmap_entity_type_alter',
      'markaspot_fastmap_markaspot_nuxt_settings_alter',
      '_markaspot_fastmap_resolve_gate_group',
      '_markaspot_fastmap_account_bypasses_tier_limit',
    ];
    foreach ($functions as $function) {
      $this->assertSame($module, (new \ReflectionFunction($function))->getFileName(), $function);
    }
  }

  /**
   * Presave asks the gate first, also for reports that arrive unpublished.
   */
  public function testPresaveDelegatesBeforeTheUnpublishedReturn(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('service_request');
    $node->method('isPublished')->willReturn(FALSE);
    $this->gate->expects($this->once())->method('enforceOnSave')
      ->with($node, $this->isInstanceOf(AccountProxyInterface::class), $this->isCallable(), FALSE)
      ->willThrowException(new WorkspaceNotOpenException());

    $this->expectException(WorkspaceNotOpenException::class);
    markaspot_fastmap_node_presave($node);
  }

  /**
   * The staff bypass of the tier limit is the gate's decision.
   *
   * The presave path and the TierLimit constraint both end there, with the
   * two-argument signature other modules' tests call.
   */
  public function testTierLimitBypassDelegatesToTheGate(): void {
    $account = $this->createMock(AccountInterface::class);
    $group = $this->createMock(GroupInterface::class);
    $this->gate->expects($this->exactly(2))->method('accountBypassesTierLimit')
      ->willReturnCallback(fn(AccountInterface $a, ?GroupInterface $g): bool => $a === $account && $g === $group);
    $this->assertTrue(_markaspot_fastmap_account_bypasses_tier_limit($account, $group));
    $this->assertFalse(_markaspot_fastmap_account_bypasses_tier_limit($account, NULL));
  }

  /**
   * The hourly expiry reminder and teardown leave record workspaces alone.
   *
   * Both workspaces look like demos (an expiry, no subscription): the record
   * workspace gets no reminder and is not torn down, the old one is torn
   * down as before.
   */
  public function testCronSkipsRecordWorkspaces(): void {
    $now = 1_800_000_000;
    $record = $this->demoLookingGroup(5, $now - 60);
    $old = $this->demoLookingGroup(6, $now - 60);
    $this->gate->method('isRecordWorkspace')->willReturnCallback(fn(?GroupInterface $group): bool => $group === $record);

    $container = \Drupal::getContainer();
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn(string $key, $default = NULL) => match ($key) {
      'markaspot_fastmap.last_demo_cleanup' => 0,
      'markaspot_fastmap.last_limit_check', 'markaspot_fastmap.last_workspace_deletion_run', 'markaspot_fastmap.last_ai_usage_cleanup' => $now,
      default => $default,
    });
    $container->set('state', $state);
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn($now);
    $container->set('datetime.time', $time);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($this->createMock(ImmutableConfig::class));
    $container->set('config.factory', $configFactory);

    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    // First the reminder window, then the expired ones.
    $query->method('execute')->willReturnOnConsecutiveCalls([5 => 5], [5 => 5, 6 => 6]);
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('getQuery')->willReturn($query);
    $groups = [5 => $record, 6 => $old];
    $groupStorage->method('loadMultiple')->willReturnCallback(fn(array $ids) => array_intersect_key($groups, $ids));
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('group')->willReturn($groupStorage);
    $container->set('entity_type.manager', $entityTypeManager);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($logger);
    $container->set('logger.factory', $loggers);
    $reset = new class {

      /**
       * No template demo reset in this test.
       */
      public function runIfDue(): void {}

    };
    $container->set('markaspot_fastmap.demo_workspace_reset', $reset);
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->expects($this->never())->method('mail');
    $container->set('plugin.manager.mail', $mail);
    $provisioning = $this->createMock(WorkspaceProvisioningServiceInterface::class);
    $provisioning->expects($this->once())->method('teardownWorkspace')->with(6);
    $container->set('markaspot_fastmap.workspace_provisioning', $provisioning);

    // Not self-service (no operating mode, the module handler knows no
    // fastmap): the waiting-reports block reads nothing; the notifier service
    // is not in this container, so a call would fail the test.
    $container->set('module_handler', $this->createMock(ModuleHandlerInterface::class));

    $record->expects($this->never())->method('getMembers');
    markaspot_fastmap_cron();
  }

  /**
   * A group that reads as a demo: an expiry and no subscription.
   */
  private function demoLookingGroup(int $id, int $expiry): GroupInterface {
    $expiryItems = $this->createMock(FieldItemListInterface::class);
    $expiryItems->method('isEmpty')->willReturn(FALSE);
    $expiryItems->method('__get')->with('value')->willReturn($expiry);
    $subscription = $this->createMock(FieldItemListInterface::class);
    $subscription->method('isEmpty')->willReturn(TRUE);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn($id);
    $fields = ['field_expiry_date', 'field_stripe_subscription_id'];
    $group->method('hasField')->willReturnCallback(static fn(string $name): bool => in_array($name, $fields, TRUE));
    $group->method('get')->willReturnCallback(static fn(string $name) => $name === 'field_expiry_date' ? $expiryItems : $subscription);
    return $group;
  }

  /**
   * Other bundles never reach the gate.
   */
  public function testPresaveIgnoresOtherBundles(): void {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('page');
    $this->gate->expects($this->never())->method('enforceOnSave');
    markaspot_fastmap_node_presave($node);
  }

  /**
   * The entity type alter is unchanged.
   */
  public function testEntityTypeAlterAddsOnlyTheTierLimit(): void {
    $added = [];
    $node = $this->createMock(ContentEntityTypeInterface::class);
    $node->method('addConstraint')->willReturnCallback(function (string $name) use (&$added, $node) {
      $added[] = $name;
      return $node;
    });
    $types = ['node' => $node];
    markaspot_fastmap_entity_type_alter($types);
    $this->assertSame(['TierLimit'], $added, 'The lifecycle refusal is no constraint: it answers from the save.');
  }

  /**
   * The public settings carry open only for a workspace under the model.
   */
  public function testPublicSettingsOpenKey(): void {
    $workspace = $this->createMock(GroupInterface::class);
    $workspace->method('hasField')->willReturn(FALSE);
    $old = $this->createMock(GroupInterface::class);
    $old->method('hasField')->willReturn(FALSE);
    $this->gate->method('recordWorkspace')->willReturnCallback(fn($group) => $group === $workspace ? $workspace : NULL);
    $this->gate->method('openState')->willReturnCallback(fn($group) => $group === $workspace ? FALSE : NULL);

    $settings = ['jurisdiction' => []];
    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $workspace);
    $this->assertFalse($settings['jurisdiction']['open']);
    $this->assertTrue($settings['jurisdiction']['is_permanent']);
    $this->assertArrayNotHasKey('expiry_date', $settings['jurisdiction']);

    $settings = ['jurisdiction' => []];
    markaspot_fastmap_markaspot_nuxt_settings_alter($settings, $old);
    $this->assertArrayNotHasKey('open', $settings['jurisdiction']);
  }

}
