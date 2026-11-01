<?php

declare(strict_types=1);

namespace Drupal\request_id_repair_test\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * Makes unconditional ECA save notifications observable.
 *
 * @Action(
 *   id = "request_id_repair_test_notify",
 *   label = @Translation("Synthetic save notification"),
 *   type = "node"
 * )
 */
final class NotifyOnSave extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL): void {
    \Drupal::service('plugin.manager.mail')->mail('request_id_repair_test', 'update', 'fixture@example.invalid', 'en');
    \Drupal::queue('request_id_repair_test_notifications')->createItem(['nid' => $object->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $return_as_object ? AccessResult::allowed() : TRUE;
  }

}
