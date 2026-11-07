<?php

declare(strict_types=1);

namespace Drupal\Tests\service_request\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the re-encode of the request attributes on a service request save.
 *
 * The attributes are a formatted text field, and the editor module records a
 * file usage for every file embed in its raw value. The upload binding checks
 * that raw value on validation; the presave re-encode that drops a citizen's
 * "_markaspot_channel" runs after it and must not turn escaped markup into a
 * real embed.
 */
#[Group('service_request')]
#[RunTestsInSeparateProcesses]
final class IntakeMetadataEncodingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);

    NodeType::create([
      'type' => 'service_request',
      'name' => 'Service request',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_request_attributes',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_request_attributes',
      'entity_type' => 'node',
      'bundle' => 'service_request',
      'label' => 'Attributes',
    ])->save();

    require_once dirname(__DIR__, 3) . '/service_request.module';
  }

  /**
   * An escaped file embed stays escaped when a citizen's channel is dropped.
   */
  public function testReEncodeKeepsEscapedEmbedEscaped(): void {
    $uuid = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
    $embed = "<img data-entity-type='file' data-entity-uuid='$uuid'>";
    // What the client sends: the embed escaped as JSON unicode, so the raw
    // value the upload binding validates holds no element.
    $raw = json_encode([
      'note' => 'Pothole ' . $embed . ' near the school',
      'size' => 'large & deep',
      '_markaspot_channel' => 'staff',
    ], JSON_HEX_TAG);
    $this->assertSame([], $this->embeddedFileUuids($raw));

    $node = $this->request($raw);
    _service_request_normalize_intake_metadata($node);

    $stored = (string) $node->get('field_request_attributes')->value;
    $this->assertNotSame($raw, $stored, 'The citizen channel is dropped, so the attributes are re-encoded.');
    $this->assertStringNotContainsString('<', $stored);
    $this->assertSame([], $this->embeddedFileUuids($stored), 'The re-encoded attributes embed no file.');
    $this->assertSame([
      'note' => 'Pothole ' . $embed . ' near the school',
      'size' => 'large & deep',
    ], json_decode($stored, TRUE), 'Consumers decode the same attributes.');
  }

  /**
   * Attributes without a channel to drop are stored as the client sent them.
   */
  public function testAttributesWithoutChannelAreNotReEncoded(): void {
    $raw = '{"note":"Pothole <b>deep</b>","size":"large"}';
    $node = $this->request($raw);

    _service_request_normalize_intake_metadata($node);

    $this->assertSame($raw, $node->get('field_request_attributes')->value);
  }

  /**
   * Builds an unsaved service request with the given attributes JSON.
   */
  private function request(string $attributes): NodeInterface {
    return Node::create([
      'type' => 'service_request',
      'title' => 'Pothole',
      'field_request_attributes' => $attributes,
    ]);
  }

  /**
   * Returns the file UUIDs the editor module would record for a raw value.
   *
   * @return list<string>
   *   The embedded file UUIDs.
   *
   * @see \Drupal\editor\Hook\EditorHooks::parseFileUuids()
   */
  private function embeddedFileUuids(string $text): array {
    $uuids = [];
    $xpath = new \DOMXPath(Html::load($text));
    foreach ($xpath->query('//*[@data-entity-type="file" and @data-entity-uuid]') as $node) {
      if ($node instanceof \DOMElement) {
        $uuids[] = $node->getAttribute('data-entity-uuid');
      }
    }
    return $uuids;
  }

}
