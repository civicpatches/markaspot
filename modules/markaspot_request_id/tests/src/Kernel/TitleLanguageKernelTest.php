<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_request_id\Kernel;

use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that saving a report does not change the language of its title.
 */
#[Group('markaspot_request_id')]
final class TitleLanguageKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'taxonomy',
    'language', 'markaspot_request_id',
  ];

  /**
   * The category with English and German names.
   */
  private Term $category;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'node', 'language']);

    ConfigurableLanguage::createFromLangcode('de')->save();
    $this->config('system.site')->set('default_langcode', 'de')->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', ['language-selected' => 0])
      ->save();
    $this->container->get('language_negotiator')
      ->setCurrentUser($this->container->get('current_user'));

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    Vocabulary::create(['vid' => 'service_category', 'name' => 'Category'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_category',
      'entity_type' => 'node',
      'bundle' => 'service_request',
    ])->save();

    $this->category = Term::create([
      'vid' => 'service_category',
      'langcode' => 'en',
      'name' => 'Street lighting',
    ]);
    $this->category->addTranslation('de', ['name' => 'Straßenbeleuchtung']);
    $this->category->save();
    $this->setInterfaceLanguage('en');
  }

  /**
   * A German report keeps its title across saves in another interface language.
   */
  public function testGermanTitleSurvivesEnglishSave(): void {
    $this->config('system.site')->set('default_langcode', 'en')->save();
    $this->assertSame('en', $this->container->get('language_manager')->getDefaultLanguage()->getId());
    $this->setInterfaceLanguage('de');
    $node = $this->createReport('de');
    $node->save();
    $this->assertSame('#42-2026 Straßenbeleuchtung', $node->label());

    $this->setInterfaceLanguage('en');
    $node->save();
    $stored = $this->container->get('entity_type.manager')
      ->getStorage('node')->loadUnchanged($node->id());
    $this->assertSame('de', $stored->language()->getId());
    $this->assertSame('#42-2026 Straßenbeleuchtung', $stored->label());
    $this->assertSame('42-2026', $stored->get('request_id')->value);
  }

  /**
   * Language-neutral reports use the site default, not the interface language.
   */
  #[DataProvider('neutralLanguageProvider')]
  public function testNeutralTitleUsesSiteDefault(string $langcode): void {
    $this->assertSame('de', $this->container->get('language_manager')->getDefaultLanguage()->getId());
    $node = $this->createReport($langcode);
    $node->save();
    $stored = $this->container->get('entity_type.manager')
      ->getStorage('node')->loadUnchanged($node->id());
    // Drupal normalizes an empty node language to the site default.
    $this->assertSame($langcode ?: 'de', $stored->language()->getId());
    $this->assertSame('#42-2026 Straßenbeleuchtung', $stored->label());
  }

  /**
   * Provides language codes that do not identify a real language.
   */
  public static function neutralLanguageProvider(): array {
    return [
      'unspecified' => [LanguageInterface::LANGCODE_NOT_SPECIFIED],
      'not applicable' => [LanguageInterface::LANGCODE_NOT_APPLICABLE],
      'empty' => [''],
    ];
  }

  /**
   * Direct callers may still omit the language to use the current interface.
   */
  public function testTitleHelperKeepsInterfaceLanguageFallback(): void {
    $this->assertSame(
      '#42-2026 Street lighting',
      markaspot_request_id_create_title($this->createReport('de'), '42-2026'),
    );
  }

  /**
   * Creates a report with an existing request ID to isolate title generation.
   */
  private function createReport(string $langcode): Node {
    return Node::create([
      'type' => 'service_request',
      'langcode' => $langcode,
      'title' => 'Original title',
      'request_id' => '42-2026',
      'field_category' => $this->category->id(),
    ]);
  }

  /**
   * Selects and verifies the actual negotiated interface language.
   */
  private function setInterfaceLanguage(string $langcode): void {
    $this->config('language.negotiation')->set('selected_langcode', $langcode)->save();
    $language_manager = $this->container->get('language_manager');
    $language_manager->reset();
    $this->assertSame($langcode, $language_manager->getCurrentLanguage()->getId());
  }

}
