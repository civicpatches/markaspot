<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_notification\Unit\Mail;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemList;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\markaspot_notification\Mail\NotifyMailManager;
use Drupal\markaspot_notification\NotificationCollector;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests dashboard receipts emitted by the mail manager decorator.
 *
 * @group markaspot_notification
 * @coversDefaultClass \Drupal\markaspot_notification\Mail\NotifyMailManager
 */
final class NotifyMailManagerTest extends UnitTestCase {

  /**
   * Tests that a successful assignee mail records an assignee receipt.
   *
   * @covers ::mail
   */
  public function testAssigneeNotificationRecordsAssigneeReceipt(): void {
    $inner = $this->createMock(MailManagerInterface::class);
    $inner->expects($this->once())
      ->method('mail')
      ->with(
        'markaspot_group',
        'assignee_notification',
        'assignee@example.test',
        'de',
        $this->isType('array'),
        NULL,
        TRUE,
      )
      ->willReturn(['result' => TRUE]);

    $node = $this->createMock(NodeInterface::class);
    $node->method('uuid')->willReturn('request-uuid');
    $assignee = $this->createMock(UserInterface::class);
    $assignee->method('uuid')->willReturn('user-38');

    $collector = $this->createMock(NotificationCollector::class);
    $collector->expects($this->once())
      ->method('record')
      ->with('request-uuid', 'mail_sent', [
        'recipient_type' => 'assignee',
        'recipient_id' => 'user-38',
      ]);

    $manager = new NotifyMailManager(
      $inner,
      $collector,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->currentUser(),
    );

    $result = $manager->mail(
      'markaspot_group',
      'assignee_notification',
      'assignee@example.test',
      'de',
      ['node' => $node, 'assignee' => $assignee],
    );

    $this->assertTrue($result['result']);
  }

  /**
   * Tests that a failed assignee mail does not record a receipt.
   *
   * @covers ::mail
   */
  public function testFailedAssigneeNotificationDoesNotRecordReceipt(): void {
    $inner = $this->createMock(MailManagerInterface::class);
    $inner->method('mail')->willReturn(['result' => FALSE]);

    $collector = $this->createMock(NotificationCollector::class);
    $collector->expects($this->never())->method('record');

    $manager = new NotifyMailManager(
      $inner,
      $collector,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->currentUser(),
    );

    $manager->mail(
      'markaspot_group',
      'assignee_notification',
      'assignee@example.test',
      'de',
      [
        'node' => $this->createMock(NodeInterface::class),
        'assignee' => $this->createMock(UserInterface::class),
      ],
    );
  }

  /**
   * Tests that a markaspot_mail notification to the reporter is recorded.
   *
   * @covers ::mail
   */
  public function testMarkaspotMailNotificationRecordsReporterReceipt(): void {
    $inner = $this->createMock(MailManagerInterface::class);
    $inner->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $collector = $this->createMock(NotificationCollector::class);
    $collector->expects($this->once())
      ->method('record')
      ->with('request-uuid', 'mail_sent', ['recipient_type' => 'reporter']);

    $manager = new NotifyMailManager(
      $inner,
      $collector,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->currentUser(),
    );

    $manager->mail(
      'markaspot_mail',
      'notification_status_closed',
      'Citizen <Citizen@Example.test>',
      'de',
      [
        'node' => $this->reporterNode('citizen@example.test'),
        'notification_key' => 'status_closed',
      ],
    );
  }

  /**
   * Tests that mails sent on behalf of anonymous users are never recorded.
   *
   * A citizen's own report POST triggers report_confirmation. Recording it
   * would write to the private tempstore and start an anonymous session.
   *
   * @covers ::mail
   */
  public function testAnonymousSenderNeverRecordsReceipt(): void {
    $inner = $this->createMock(MailManagerInterface::class);
    $inner->expects($this->once())
      ->method('mail')
      ->willReturn(['result' => TRUE]);

    $collector = $this->createMock(NotificationCollector::class);
    $collector->expects($this->never())->method('record');
    $collector->expects($this->never())->method('getCurrentNodeUuid');

    $manager = new NotifyMailManager(
      $inner,
      $collector,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->currentUser(TRUE),
    );

    $result = $manager->mail(
      'markaspot_mail',
      'notification_report_confirmation',
      'citizen@example.test',
      'de',
      [
        'node' => $this->reporterNode('citizen@example.test'),
        'notification_key' => 'report_confirmation',
      ],
    );

    $this->assertTrue($result['result']);
  }

  /**
   * Tests that non-notification mails and failed sends stay unrecorded.
   *
   * @covers ::mail
   */
  #[DataProvider('unrecordedMailProvider')]
  public function testMailWithoutReportNotificationIsNotRecorded(string $module, string $key, bool $sent): void {
    $inner = $this->createMock(MailManagerInterface::class);
    $inner->method('mail')->willReturn(['result' => $sent]);

    $collector = $this->createMock(NotificationCollector::class);
    $collector->expects($this->never())->method('record');

    $manager = new NotifyMailManager(
      $inner,
      $collector,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->currentUser(),
    );

    $manager->mail(
      $module,
      $key,
      'citizen@example.test',
      'de',
      ['node' => $this->reporterNode('citizen@example.test')],
    );
  }

  /**
   * Provides real mail invocations that must not produce a receipt.
   *
   * @return array<string, array{string, string, bool}>
   *   Mail module, mail key and send result.
   */
  public static function unrecordedMailProvider(): array {
    return [
      'login code' => ['markaspot_passwordless', 'verification_code', TRUE],
      'inbound triage reply' => ['markaspot_mail_inbound', 'triage_reply', TRUE],
      'workspace welcome' => ['markaspot_fastmap', 'workspace_welcome', TRUE],
      'failed notification' => ['markaspot_mail', 'notification_status_closed', FALSE],
    ];
  }

  /**
   * Builds the current user, authenticated unless stated otherwise.
   */
  private function currentUser(bool $anonymous = FALSE): AccountProxyInterface {
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('isAnonymous')->willReturn($anonymous);
    return $account;
  }

  /**
   * Builds a service_request node mock carrying a reporter address.
   */
  private function reporterNode(string $email): NodeInterface {
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getValue')->willReturn(['value' => $email]);
    $items = $this->createMock(FieldItemList::class);
    $items->method('isEmpty')->willReturn(FALSE);
    $items->method('getIterator')->willReturn(new \ArrayIterator([$item]));

    $node = $this->createMock(NodeInterface::class);
    $node->method('uuid')->willReturn('request-uuid');
    $node->method('hasField')->willReturnCallback(static fn (string $field): bool => $field === 'field_e_mail');
    $node->method('get')->with('field_e_mail')->willReturn($items);
    return $node;
  }

}
