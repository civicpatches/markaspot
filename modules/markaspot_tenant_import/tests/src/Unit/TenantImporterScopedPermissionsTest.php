<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_tenant_import\Unit;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_tenant_import\Service\TenantConfigValidator;
use Drupal\markaspot_tenant_import\Service\TenantImportFieldMapper;
use Drupal\markaspot_tenant_import\Service\TenantImporter;
use Drupal\Tests\UnitTestCase;
use Drupal\user\PermissionHandlerInterface;
use Drupal\user\RoleInterface;

/**
 * Tests the administrative-permission guard for scoped organisation roles.
 *
 * @group markaspot_tenant_import
 * @coversDefaultClass \Drupal\markaspot_tenant_import\Service\TenantImporter
 */
final class TenantImporterScopedPermissionsTest extends UnitTestCase {

  /**
   * Restricted permission definitions as the fleet ships them.
   */
  private const DEFINITIONS = [
    'access dashboard notifications' => 'markaspot_notification',
    'add dashboard status notes' => 'markaspot_dashboard',
    'administer markaspot mail inbound' => 'markaspot_mail_inbound',
    'assign service requests' => 'markaspot_group',
    'bypass mas validation' => 'markaspot_validation',
    'manage dashboard notes' => 'markaspot_dashboard',
    'split service requests' => 'markaspot_dashboard',
    'triage inbound mail' => 'markaspot_mail_inbound',
    'use service request management form' => 'markaspot_ui',
  ];

  /**
   * A moderator role holding the inbound-mail triage permission passes.
   *
   * @covers ::administrativePermissions
   */
  public function testModeratorWithInboundMailTriagePasses(): void {
    $offending = $this->offendingPermissions('org_moderator', [
      'access content',
      'add dashboard status notes',
      'triage inbound mail',
      'use service request management form',
    ]);

    $this->assertSame([], $offending);
  }

  /**
   * Mailbox administration and a drifted triage provider stay refused.
   *
   * @covers ::administrativePermissions
   */
  public function testInboundMailAdministrationStaysRefused(): void {
    $this->assertSame(
      ['administer markaspot mail inbound'],
      $this->offendingPermissions('org_moderator', [
        'administer markaspot mail inbound',
        'triage inbound mail',
      ]),
    );

    // The exception is bound to the shipping module, not to the name alone.
    $this->assertSame(
      ['triage inbound mail'],
      $this->offendingPermissions('org_moderator', ['triage inbound mail'], [
        'triage inbound mail' => 'system',
      ]),
    );
  }

  /**
   * Contractors never triage unredacted inbound citizen mail.
   *
   * @covers ::administrativePermissions
   */
  public function testContractorWithInboundMailTriageIsRefused(): void {
    $this->assertSame(
      ['triage inbound mail'],
      $this->offendingPermissions('org_contractor', [
        'add dashboard status notes',
        'triage inbound mail',
      ]),
    );
  }

  /**
   * Runs the private guard for one import role against a Drupal role.
   *
   * @param string $sourceRole
   *   The import role key in TenantImporter::SCOPED_ORG_ROLES.
   * @param string[] $permissions
   *   Permissions the Drupal role holds.
   * @param array<string, string> $providerOverrides
   *   Definition providers that differ from the shipped ones.
   *
   * @return string[]
   *   The offending permissions the guard reports.
   */
  private function offendingPermissions(string $sourceRole, array $permissions, array $providerOverrides = []): array {
    $definitions = ['access content' => ['title' => 'access content', 'provider' => 'node']];
    foreach ($providerOverrides + self::DEFINITIONS as $permission => $provider) {
      $definitions[$permission] = [
        'title' => $permission,
        'provider' => $provider,
        'restrict access' => TRUE,
      ];
    }
    $permissionHandler = $this->createMock(PermissionHandlerInterface::class);
    $permissionHandler->method('getPermissions')->willReturn($definitions);

    $importer = new TenantImporter(
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(EntityFieldManagerInterface::class),
      $this->createMock(JurisdictionHierarchyResolverInterface::class),
      $this->createMock(Connection::class),
      new TenantConfigValidator($this->createMock(EmailValidatorInterface::class)),
      new TenantImportFieldMapper(),
      $this->createMock(PasswordGeneratorInterface::class),
      $this->createMock(LockBackendInterface::class),
      $permissionHandler,
    );

    $role = $this->createMock(RoleInterface::class);
    $role->method('isAdmin')->willReturn(FALSE);
    $role->method('getPermissions')->willReturn($permissions);

    $scopedRoles = (new \ReflectionClassConstant(TenantImporter::class, 'SCOPED_ORG_ROLES'))->getValue();
    $method = new \ReflectionMethod(TenantImporter::class, 'administrativePermissions');
    return $method->invoke($importer, $role, $scopedRoles[$sourceRole]['scoped_permissions']);
  }

}
