<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\markaspot_mail\Service\StatusMailKeyAnalyzer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Verifies strict path equivalence without changing any entity.
 */
#[Group('markaspot_mail')]
final class StatusMailKeyAnalyzerTest extends UnitTestCase {

  /**
   * Uses the shipped graph structure, with neutral keyed mail actions.
   */
  public static function models(): array {
    $path = dirname(__DIR__, 5) . '/markaspot_group/config/optional/eca.eca.';
    $old = Yaml::decode(file_get_contents($path . 'process_tunr6d6.yml'));
    $old['status'] = TRUE;
    foreach (array_combine(array_keys($old['actions']), ['status_closed', 'status_not_responsible', 'status_open']) as $id => $key) {
      $old['actions'][$id] = [
        'plugin' => 'markaspot_mail_send_notification',
        'configuration' => [
          'object' => 'entity',
          'recipient' => '[node:field_e_mail:value]',
          'notification_key' => $key,
        ],
        'successors' => [],
      ];
    }
    return [$old, Yaml::decode(file_get_contents($path . 'process_notify_status_term.yml'))];
  }

  /**
   * The plan comes from keys, including a dead branch and repeated keys.
   */
  public function testKeyActionsAndDeadBranch(): void {
    [$old, $term] = self::models();
    $old['actions']['Activity_1rxwbas']['plugin'] = 'action_send_email_action';
    $plan = (new StatusMailKeyAnalyzer())->analyze($old, $term, self::terms());
    $this->assertSame([5 => 'status_closed', 4 => 'status_open'], $plan['keys']);
    $this->assertSame('dead branch excluded', $plan['rows'][1]['verdict']);
    $this->assertSame('ready to migrate', $plan['result']);
    $old['status'] = FALSE;
    $terms = self::terms();
    $terms[4]['key'] = 'status_open';
    $terms[5]['key'] = 'status_closed';
    $this->assertSame('already migrated', (new StatusMailKeyAnalyzer())->analyze($old, $term, $terms)['result']);
  }

  /**
   * Every unproved path must fail before a migration can obtain a plan.
   */
  #[DataProvider('unsafeCases')]
  public function testRejectsUnprovedPaths(string $case): void {
    [$old, $term] = self::models();
    $terms = self::terms();
    switch ($case) {
      case 'raw':
        $old['actions']['Activity_1u8e4au']['plugin'] = 'action_send_email_action';
        break;

      case 'negated':
        $old['conditions']['Flow_0kbs1sz']['configuration']['negate'] = TRUE;
        break;

      case 'operator':
        $old['conditions']['Flow_0kbs1sz']['configuration']['operator'] = 'greaterthan';
        break;

      case 'duplicate':
        $old['gateways']['Gateway_0nyfe5b']['successors'][] = $old['gateways']['Gateway_0nyfe5b']['successors'][0];
        break;

      case 'recipient':
        $old['actions']['Activity_1u8e4au']['configuration']['recipient'] = 'other@example.test';
        break;

      case 'binding':
        unset($old['actions']['Activity_1u8e4au']['configuration']['object']);
        break;

      case 'opt-in':
        $old['conditions']['Flow_0ktblta']['configuration']['expected_value'] = '0';
        break;

      case 'overlap':
        $terms[5]['key'] = 'status_closed';
        break;

      case 'conflict':
        $terms[5]['key'] = 'status_open';
        break;

      case 'incomplete':
        $old['status'] = FALSE;
        break;

      case 'key':
        $old['actions']['Activity_1u8e4au']['configuration']['notification_key'] = 'report_confirmation';
        break;

      case 'term-recipient':
        $term['actions']['Activity_send_term_notification']['configuration']['recipient'] = 'other@example.test';
        break;

      case 'term-gate':
        $term['conditions']['Condition_notification_enabled']['configuration']['negate'] = TRUE;
        break;

      case 'term-binding':
        unset($term['actions']['Activity_send_term_notification']['configuration']['object']);
        break;

      case 'event':
        $old['events']['Event_04bz8yl']['configuration']['type'] = 'node page';
        break;

      case 'extra-action':
        $old['actions']['Activity_1u8e4au']['successors'] = [['id' => 'extra', 'condition' => '']];
        $old['actions']['extra'] = ['plugin' => 'eca_switch_account', 'configuration' => [], 'successors' => []];
        break;

      case 'dead-prefix-action':
        $old['gateways']['Gateway_02ldgot']['successors'][0]['id'] = 'prefix';
        $old['actions']['prefix'] = [
          'plugin' => 'eca_switch_account',
          'configuration' => [],
          'successors' => [['id' => 'Activity_1rxwbas', 'condition' => 'Flow_0bs2byv']],
        ];
        break;

      case 'template':
        $term['events']['Event_status_update']['applied_templates'] = ['unknown'];
        break;

      case 'wrong-bundle':
        $terms[5]['compatible'] = FALSE;
        break;

      case 'late-gate':
        $old['gateways']['Gateway_0nyfe5b']['successors'][0]['condition'] = '';
        $old['actions']['Activity_1u8e4au']['successors'] = [['id' => 'late', 'condition' => 'Flow_0kbs1sz']];
        $old['gateways']['late'] = ['type' => 0, 'successors' => []];
        break;

      case 'cycle':
        $old['actions']['Activity_1u8e4au']['successors'] = [['id' => 'Gateway_02ldgot', 'condition' => '']];
        break;
    }
    $this->expectException(\RuntimeException::class);
    (new StatusMailKeyAnalyzer())->analyze($old, $term, $terms);
  }

  /**
   * Unsafe graph variations.
   */
  public static function unsafeCases(): array {
    return array_combine($cases = [
      'raw',
      'negated',
      'operator',
      'duplicate',
      'recipient',
      'binding',
      'opt-in',
      'conflict',
      'incomplete',
      'key',
      'term-recipient',
      'term-gate',
      'term-binding',
      'event',
      'extra-action',
      'cycle',
      'overlap',
      'dead-prefix-action',
      'template',
      'wrong-bundle',
      'late-gate',
    ], array_map(static fn(string $case): array => [$case], $cases));
  }

  /**
   * Neutral fixture terms; status 30 intentionally does not exist.
   */
  private static function terms(): array {
    return [
      4 => ['name' => 'Open', 'key' => '', 'jurisdiction' => '1'],
      5 => ['name' => 'Closed', 'key' => '', 'jurisdiction' => '1'],
    ];
  }

}
