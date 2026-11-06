<?php

declare(strict_types=1);

namespace Drupal\markaspot_passwordless\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_passwordless\Controller\FormLayoutPreferencesController;

/**
 * Reads and writes the workspace default of the edit form section layout.
 *
 * The default belongs to the tenant: it is stored on the ROOT jurisdiction's
 * field_nuxt_config at staffForms.editLayout, and every sub-jurisdiction reads
 * the root value. Only layouts that pass
 * FormLayoutPreferencesController::validLayout() are stored or returned.
 *
 * "staffForms" is deliberately not one of the keys that the public settings
 * response (MarkASpotSettingsController::getMarkASpotSettings()) copies from
 * field_nuxt_config, so anonymous visitors never see the staff form layout.
 */
class WorkspaceFormLayoutStore {

  /**
   * Top-level field_nuxt_config key that holds staff-only form settings.
   */
  public const CONFIG_KEY = 'staffForms';

  /**
   * Key of the edit form layout below CONFIG_KEY.
   */
  public const LAYOUT_KEY = 'editLayout';

  /**
   * Constructs the store.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
  ) {}

  /**
   * Returns the jurisdiction that holds the default for a jurisdiction.
   *
   * @param int $jurisdictionId
   *   A jurisdiction group ID.
   *
   * @return int
   *   The root jurisdiction ID; the jurisdiction itself when it is a root or
   *   its hierarchy cannot be resolved.
   */
  public function rootId(int $jurisdictionId): int {
    return $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId) ?? $jurisdictionId;
  }

  /**
   * Returns the workspace default that applies to a jurisdiction.
   *
   * @param int $jurisdictionId
   *   A jurisdiction group ID; sub-jurisdictions get their root's value.
   *
   * @return array|null
   *   The stored layout, or NULL when none or no valid one is stored.
   */
  public function get(int $jurisdictionId): ?array {
    $root = $this->entityTypeManager->getStorage('group')->load($this->rootId($jurisdictionId));
    return $root instanceof GroupInterface ? self::readLayout(self::nuxtConfig($root)) : NULL;
  }

  /**
   * Stores or removes the workspace default on the root jurisdiction.
   *
   * @param int $jurisdictionId
   *   A jurisdiction group ID; the value is always written to its root.
   * @param array|null $layout
   *   A layout that passes FormLayoutPreferencesController::validLayout(), or
   *   NULL to remove the default.
   *
   * @return array|null
   *   The stored value.
   *
   * @throws \InvalidArgumentException
   *   When the layout is invalid, or the root has no field_nuxt_config or one
   *   that is not valid JSON.
   */
  public function set(int $jurisdictionId, ?array $layout): ?array {
    if ($layout !== NULL && !FormLayoutPreferencesController::validLayout($layout)) {
      throw new \InvalidArgumentException('Invalid form layout.');
    }
    // A fresh copy, so no stale field value of this request is written back.
    $root = $this->entityTypeManager->getStorage('group')->loadUnchanged($this->rootId($jurisdictionId));
    if (!$root instanceof GroupInterface || !$root->hasField('field_nuxt_config')) {
      throw new \InvalidArgumentException('The root jurisdiction cannot store a form layout.');
    }
    $source = $root->isDefaultTranslation() ? $root : $root->getUntranslated();
    $raw = $source->get('field_nuxt_config')->isEmpty() ? '' : (string) $source->get('field_nuxt_config')->value;
    if (trim($raw) !== '' && !is_array(json_decode($raw, TRUE))) {
      // Never replace a configuration that cannot be read with a new one.
      throw new \InvalidArgumentException('The root jurisdiction configuration is not valid JSON.');
    }
    $config = self::nuxtConfig($source);
    $updated = self::withLayout($config, $layout);
    if ($updated !== $config) {
      $source->set('field_nuxt_config', json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
      $source->save();
    }
    return self::readLayout($updated);
  }

  /**
   * Reads a valid layout from decoded field_nuxt_config.
   *
   * @param array $config
   *   The decoded configuration.
   *
   * @return array|null
   *   The layout, or NULL when it is missing or invalid.
   */
  public static function readLayout(array $config): ?array {
    $section = $config[self::CONFIG_KEY] ?? NULL;
    $layout = is_array($section) ? ($section[self::LAYOUT_KEY] ?? NULL) : NULL;
    return FormLayoutPreferencesController::validLayout($layout) ? $layout : NULL;
  }

  /**
   * Returns decoded field_nuxt_config with the layout set or removed.
   *
   * Other keys, also other keys below CONFIG_KEY, stay as they are. Removing
   * the last key below CONFIG_KEY removes CONFIG_KEY as well.
   *
   * @param array $config
   *   The decoded configuration.
   * @param array|null $layout
   *   The layout, or NULL to remove it.
   *
   * @return array
   *   The updated configuration.
   */
  public static function withLayout(array $config, ?array $layout): array {
    $section = is_array($config[self::CONFIG_KEY] ?? NULL) ? $config[self::CONFIG_KEY] : [];
    if ($layout === NULL) {
      if (!array_key_exists(self::LAYOUT_KEY, $section)) {
        return $config;
      }
      unset($section[self::LAYOUT_KEY]);
      if ($section === []) {
        unset($config[self::CONFIG_KEY]);
        return $config;
      }
    }
    else {
      $section[self::LAYOUT_KEY] = $layout;
    }
    $config[self::CONFIG_KEY] = $section;
    return $config;
  }

  /**
   * Decodes field_nuxt_config from the group's default translation.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The jurisdiction group.
   *
   * @return array
   *   The decoded configuration, an empty array when unset or invalid.
   */
  private static function nuxtConfig(GroupInterface $group): array {
    $source = $group->isDefaultTranslation() ? $group : $group->getUntranslated();
    if (!$source->hasField('field_nuxt_config') || $source->get('field_nuxt_config')->isEmpty()) {
      return [];
    }
    $decoded = json_decode((string) $source->get('field_nuxt_config')->value, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
