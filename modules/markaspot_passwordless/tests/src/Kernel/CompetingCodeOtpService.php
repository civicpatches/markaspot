<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_passwordless\Kernel;

use Drupal\markaspot_passwordless\Service\OtpService;

/**
 * OTP service that can issue a competing code while a new one is generated.
 *
 * GenerateCode() runs between the invalidation of old codes and the insert
 * of the new one, which is where a parallel request would add its own row.
 * Mail is not sent.
 */
final class CompetingCodeOtpService extends OtpService {

  /**
   * Whether the next generateCode() call inserts a competing pending code.
   */
  public static bool $issueCompetingCode = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function generateCode(): string {
    if (self::$issueCompetingCode) {
      \Drupal::database()->insert('markaspot_passwordless_codes')
        ->fields([
          'email' => 'person@example.test',
          'code' => password_hash('333333', PASSWORD_BCRYPT),
          'attempts' => 0,
          'created' => time(),
          'expires' => time() + 1800,
          'verified' => 0,
          'jurisdiction_id' => 0,
        ])
        ->execute();
    }
    return parent::generateCode();
  }

  /**
   * {@inheritdoc}
   */
  protected function sendCode(string $email, string $code, int $code_lifetime, string $langcode = '', int $jurisdiction_id = 0): bool {
    return TRUE;
  }

}
