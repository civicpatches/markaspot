<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refuses a publish at the Free limit of a workspace with a lifecycle record.
 *
 * The message starts with the machine code, followed by the human sentence
 * that names the limit and both ways out. Thrown from the presave backstop of
 * markaspot_fastmap_node_presave(); JSON:API answers it (through the
 * markaspot_group storage exception subscriber) with a 422 whose error
 * detail is this message. The TierLimit constraint uses prefix() for its
 * violation, so both refusals carry the same code.
 */
final class PublishedLimitReachedException extends HttpException {

  /**
   * The machine code of the refusal.
   */
  public const CODE = 'published_limit_reached';

  /**
   * Constructs the exception from the human sentence.
   */
  public function __construct(string $sentence) {
    parent::__construct(422, self::prefix($sentence));
  }

  /**
   * Prefixes a human sentence with the machine code.
   */
  public static function prefix(string $sentence): string {
    return self::CODE . ': ' . $sentence;
  }

}
