<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\markaspot_nuxt\Service\ReviewBeforePublish;
use Drupal\node\NodeInterface;

/**
 * Keeps reports held for review unpublished with explicit hook ordering.
 */
final class ReviewBeforePublishHooks {

  /**
   * Constructs the hook service.
   */
  public function __construct(
    private readonly ReviewBeforePublish $reviewBeforePublish,
  ) {}

  /**
   * Reasserts the hold after every other entity presave hook.
   *
   * Content Moderation's entity presave turns the late re-save of a report
   * held after insert into a forward revision. Module list order decides
   * whether a plain hook runs before or after it (a site sorted by name runs
   * content_moderation first, a module enabled later is appended), so the
   * order is explicit.
   */
  #[Hook('entity_presave', order: Order::Last)]
  public function entityPresave(EntityInterface $entity): void {
    if ($entity instanceof NodeInterface) {
      $this->reviewBeforePublish->reassertHold($entity);
    }
  }

}
