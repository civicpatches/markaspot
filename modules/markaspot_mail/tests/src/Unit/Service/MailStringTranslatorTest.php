<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Unit\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\markaspot_mail\Service\MailStringTranslator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests mail language on sites with and without Locale.
 */
#[CoversClass(MailStringTranslator::class)]
#[Group('markaspot_mail')]
final class MailStringTranslatorTest extends UnitTestCase {

  /**
   * Reads the shipped strings and escapes mail placeholders.
   */
  public function testUsesShippedTranslationsWithoutLocale(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('locale')->willReturn(FALSE);
    $core = $this->createMock(TranslationInterface::class);
    $core->expects($this->never())->method('translate');
    $translator = new MailStringTranslator($modules, $core);

    $this->assertSame('E-Mail-Adresse bestätigen', $translator->translate('Confirm your email address', [], 'de'));
    $this->assertSame('Datenschutz', $translator->translate('Privacy', [], 'de'));
    $this->assertSame('Privacy', $translator->translate('Privacy', [], 'en'));
    $this->assertSame('Missing', $translator->translate('Missing', [], 'de'));
    $this->assertSame('Privacy', $translator->translate('Privacy', [], '../de'));
    $attribution = $translator->translate('This e-mail was sent automatically by @name via the Mark-a-Spot platform.', ['@name' => '<Map>'], 'de');
    $this->assertStringContainsString('&lt;Map&gt;', $attribution);
    $this->assertStringNotContainsString('was sent automatically', $attribution);
    $this->assertSame('Die Meldung wurde erstellt.', $translator->translate('The service request has been created.', [], 'de', 'service_request'));
  }

  /**
   * Leaves translations entirely to Drupal when Locale is enabled.
   */
  public function testUsesDrupalWithLocale(): void {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->with('locale')->willReturn(TRUE);
    $core = $this->createMock(TranslationInterface::class);
    $core->expects($this->once())->method('translate')
      ->with('Privacy', [], ['langcode' => 'de'])
      ->willReturn(new TranslatableMarkup('Custom tenant translation', [], [], $this->getStringTranslationStub()));
    $this->assertSame('Custom tenant translation', (new MailStringTranslator($modules, $core))->translate('Privacy', [], 'de'));
  }

}
