<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_fastmap\Unit;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_fastmap\Service\OperatorDetails;

/**
 * Builds workspace doubles that carry operator fields.
 */
trait OperatorWorkspaceTrait {

  /**
   * Builds a workspace double with operator fields and other field values.
   *
   * @param array|null $address
   *   Address properties, or NULL for an empty address field.
   * @param string|null $email
   *   The contact e-mail, or NULL when empty.
   * @param bool $hasFields
   *   Whether the group has the operator fields at all.
   * @param array<string, mixed> $values
   *   Values of further single-value fields (read through ->value), or a
   *   field item list to return as it is.
   */
  protected function operatorWorkspace(?array $address, ?string $email, bool $hasFields = TRUE, array $values = []): GroupInterface {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('get')->willReturnCallback(function (string $property) use ($address): TypedDataInterface {
      $data = $this->createMock(TypedDataInterface::class);
      $data->method('getValue')->willReturn($address[$property] ?? NULL);
      return $data;
    });
    $addressItems = $this->createMock(FieldItemListInterface::class);
    $addressItems->method('isEmpty')->willReturn($address === NULL);
    $addressItems->method('first')->willReturn($address === NULL ? NULL : $item);
    $values[OperatorDetails::EMAIL_FIELD] = $email;

    $lists = [];
    foreach ($values as $name => $value) {
      if ($value instanceof FieldItemListInterface) {
        $lists[$name] = $value;
        continue;
      }
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('isEmpty')->willReturn($value === NULL);
      $list->method('__get')->with('value')->willReturn($value);
      $lists[$name] = $list;
    }
    $lists[OperatorDetails::ADDRESS_FIELD] = $addressItems;

    $group = $this->createMock(GroupInterface::class);
    $group->method('hasField')->willReturnCallback(static fn(string $name): bool => isset($lists[$name])
      && ($hasFields || !in_array($name, [OperatorDetails::ADDRESS_FIELD, OperatorDetails::EMAIL_FIELD], TRUE)));
    $group->method('get')->willReturnCallback(fn(string $name) => $lists[$name] ?? $this->createMock(FieldItemListInterface::class));
    return $group;
  }

}
