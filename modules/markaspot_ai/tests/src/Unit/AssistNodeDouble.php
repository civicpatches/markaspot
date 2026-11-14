<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_ai\Unit;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;

/**
 * A stored report double whose field values follow set(), clone-safe.
 *
 * Built without the entity constructor; only the methods the assist path
 * uses are answered. A clone owns its own field list, so setting draft values
 * on the clone never reaches the stored report, as with a real entity.
 */
final class AssistNodeDouble extends Node {

  /**
   * Field lists keyed by field name.
   *
   * @var array<string, \Drupal\Core\Field\FieldItemListInterface>
   */
  private array $doubleFields = [];

  /**
   * Builds a field list from a value passed to set().
   *
   * @var \Closure(string, mixed): \Drupal\Core\Field\FieldItemListInterface
   */
  private \Closure $fieldFactory;

  /**
   * Node ID.
   */
  private int $doubleId = 0;

  /**
   * Title.
   */
  private string $doubleLabel = '';

  /**
   * Builds a stored report.
   *
   * @param int $id
   *   The node ID.
   * @param string $label
   *   The title.
   * @param array<string, \Drupal\Core\Field\FieldItemListInterface> $fields
   *   The stored field lists.
   * @param \Closure $fieldFactory
   *   Turns a set() value into a field list.
   */
  public static function build(int $id, string $label, array $fields, \Closure $fieldFactory): self {
    $node = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
    $node->doubleId = $id;
    $node->doubleLabel = $label;
    $node->doubleFields = $fields;
    $node->fieldFactory = $fieldFactory;
    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->doubleId;
  }

  /**
   * {@inheritdoc}
   */
  public function bundle() {
    return 'service_request';
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->doubleLabel;
  }

  /**
   * {@inheritdoc}
   */
  public function access($operation = 'view', ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasField($field_name) {
    return isset($this->doubleFields[$field_name]);
  }

  /**
   * {@inheritdoc}
   */
  public function get($field_name): FieldItemListInterface {
    if (!isset($this->doubleFields[$field_name])) {
      throw new \InvalidArgumentException("Field $field_name is unknown.");
    }
    return $this->doubleFields[$field_name];
  }

  /**
   * {@inheritdoc}
   */
  public function set($name, $value, $notify = TRUE) {
    $this->doubleFields[$name] = ($this->fieldFactory)($name, $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function save(): never {
    throw new \LogicException('The stored report must never be saved by the assistant.');
  }

  /**
   * {@inheritdoc}
   */
  public function __clone() {
    // The field array is copied by value; set() replaces entries, so the
    // original keeps its stored values. The entity internals are not used.
  }

}
