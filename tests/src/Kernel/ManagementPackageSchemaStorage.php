<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot\Kernel;

use Drupal\Core\Config\ExtensionInstallStorage;
use Drupal\Core\Config\FileStorage;

/**
 * Supplies the real group filter schema without booting group access services.
 */
final class ManagementPackageSchemaStorage extends ExtensionInstallStorage {

  /**
   * {@inheritdoc}
   */
  protected function getAllFolders() {
    $folders = parent::getAllFolders();
    $path = dirname(__DIR__, 3) . '/modules/markaspot_group/config/schema';
    foreach ((new FileStorage($path))->listAll() as $name) {
      $folders[$name] = $path;
    }
    return $folders;
  }

}
