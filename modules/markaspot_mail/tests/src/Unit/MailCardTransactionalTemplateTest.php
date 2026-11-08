<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders mail-card-transactional.html.twig with real Twig.
 *
 * The card uses no Drupal Twig extensions (only |raw and |default), so a
 * plain Twig environment with HTML autoescaping renders it exactly like
 * the theme layer does, whitespace included. Mails without a second staff
 * link must stay byte-identical to the card before the secondary CTA
 * existed: the fixtures under tests/fixtures/mail-card-transactional were
 * rendered from that template with the content below. Regenerate them only
 * for an intended change of the card markup.
 */
#[Group('markaspot_mail')]
final class MailCardTransactionalTemplateTest extends UnitTestCase {

  /**
   * Content of mails that carry no secondary CTA, keyed by fixture name.
   *
   * @return array<string, array{0: string, 1: array<string, mixed>}>
   *   Fixture file name and card content.
   */
  public static function contentWithoutSecondaryCta(): array {
    return [
      'citizen confirmation' => ['citizen.html', [
        'headline' => 'Your report #7-2026 was received',
        'intro' => 'Thank you for your report.<br>We will look into it.',
        'body_blocks' => ['Category: Road damage', 'Location: Main Street 1'],
        'cta_label' => 'View report',
        'cta_url' => 'https://melder.example.org/bonn/requests/7-2026',
        'cta_url_is_safe' => TRUE,
        'contact_block' => ['City of Bonn', 'Phone 0228 77 0'],
      ]],
      'staff dashboard link' => ['staff-dashboard.html', [
        'headline' => 'Request #7-2026 assigned to Road Works',
        'intro' => 'A new request was assigned to your organisation.',
        'body_blocks' => ['Please review the request.', 'Description: Pothole & cracks'],
        'features_block' => [
          ['Request' => '#7-2026'],
          ['Category' => 'Road & Park'],
          ['Location' => 'Main Street 1'],
        ],
        'cta_label' => 'Open request',
        'cta_url' => 'https://melder.example.org/bonn/dashboard/requests/7-2026/edit',
        'cta_url_is_safe' => TRUE,
      ]],
      'unsafe primary link' => ['unsafe-cta.html', [
        'headline' => 'Request #7-2026',
        'body_blocks' => ['Body'],
        'cta_label' => 'Open request',
        'cta_url' => 'http://127.0.0.1:8080/node/7',
        'cta_url_is_safe' => FALSE,
      ]],
    ];
  }

  /**
   * Tests mails without a second link render exactly as before.
   *
   * @param string $fixture
   *   Fixture file with the output of the card before the secondary CTA.
   * @param array<string, mixed> $content
   *   Card content.
   */
  #[DataProvider('contentWithoutSecondaryCta')]
  public function testCardWithoutSecondaryCtaIsUnchanged(string $fixture, array $content): void {
    $expected = file_get_contents(dirname(__DIR__, 2) . '/fixtures/mail-card-transactional/' . $fixture);
    $this->assertIsString($expected);

    $this->assertSame($expected, $this->render($content));
  }

  /**
   * Tests the second link renders as an outlined button after the first.
   */
  public function testSecondaryCtaRendersAfterThePrimaryButton(): void {
    $html = $this->render([
      'headline' => 'Request #7-2026',
      'cta_label' => 'Open request',
      'cta_url' => 'https://melder.example.org/bonn/dashboard/requests/7-2026/edit',
      'cta_url_is_safe' => TRUE,
      'secondary_cta_label' => 'Road & Park in the backend',
      'secondary_cta_url' => 'https://management.example.org/node/7/edit',
      'secondary_cta_url_is_safe' => TRUE,
    ]);

    $primary = strpos($html, 'href="https://melder.example.org/bonn/dashboard/requests/7-2026/edit"');
    $secondary = strpos($html, 'href="https://management.example.org/node/7/edit"');
    $this->assertIsInt($primary);
    $this->assertIsInt($secondary);
    $this->assertGreaterThan($primary, $secondary);
    $this->assertStringContainsString('border:2px solid #004ced;', $html);
    $this->assertStringContainsString('Road &amp; Park in the backend', $html);
    $this->assertStringNotContainsString('&amp;amp;', $html);
  }

  /**
   * Tests an unsafe second link degrades to text.
   */
  public function testUnsafeSecondaryCtaRendersAsText(): void {
    $html = $this->render([
      'secondary_cta_label' => 'Open in the backend',
      'secondary_cta_url' => 'http://127.0.0.1:8080/node/7/edit',
      'secondary_cta_url_is_safe' => FALSE,
    ]);

    $this->assertStringNotContainsString('href="http://127.0.0.1:8080/node/7/edit"', $html);
    $this->assertStringContainsString('Open in the backend: http://127.0.0.1:8080/node/7/edit', $html);
  }

  /**
   * Renders the card with a fixed primary color.
   *
   * @param array<string, mixed> $content
   *   Card content.
   */
  private function render(array $content): string {
    $twig = new Environment(
      new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
      ['autoescape' => 'html', 'strict_variables' => FALSE],
    );
    return $twig->render('mail-card-transactional.html.twig', [
      'branding' => ['primary_color' => '#004ced'],
      'content' => $content,
    ]);
  }

}
