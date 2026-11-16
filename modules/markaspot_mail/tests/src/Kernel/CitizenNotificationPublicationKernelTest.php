<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests publication-aware citizen links through the real mail render pipeline.
 */
#[Group('markaspot_mail')]
#[RunTestsInSeparateProcesses]
final class CitizenNotificationPublicationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'node', 'file', 'taxonomy',
    'options', 'datetime', 'language', 'color_field', 'geolocation', 'entity',
    'entity_reference_revisions', 'paragraphs', 'flexible_permissions', 'group',
    'gnode', 'field_permissions', 'markaspot_validation', 'markaspot_group',
    'markaspot_nuxt', 'service_request', 'markaspot_request_id', 'markaspot_mail',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'node', 'group'] as $entityType) {
      $this->installEntitySchema($entityType);
    }
    $this->installConfig(['system', 'user', 'field', 'filter', 'node', 'group']);
    NodeType::create(['type' => 'service_request', 'name' => 'Service Request'])->save();
    $this->config('markaspot_mail.settings')->set('platform', [
      'name' => 'Report portal',
      'frontend_base_url' => 'https://reports.example.org',
      'backend_base_url' => 'https://reports.example.org',
    ])->save();

    // Twig needs a namespace for templates in worktrees outside the web root.
    $registry = $this->container->get('theme.registry')->getRuntime();
    foreach (['markaspot_mail_layout', 'markaspot_mail_card_transactional'] as $hook) {
      $info = $registry->get($hook);
      if (is_array($info) && str_starts_with((string) ($info['path'] ?? ''), '../')) {
        $info['path'] = '@markaspot_mail';
        $registry->set($hook, $info);
      }
    }
  }

  /**
   * Each notification keeps its wording and only links a published report.
   */
  #[DataProvider('notificationKeys')]
  public function testPublicationControlsReportLinkInBothMailParts(string $notificationKey): void {
    $this->config('markaspot_mail.texts')->set($notificationKey, [
      'subject' => 'Update on your report',
      'headline' => 'Your report was updated',
      'intro' => 'We are processing your report.',
      'body_blocks' => ['Thank you for your report.'],
      'cta_label' => 'View your report',
      'preheader' => 'An update on your report.',
    ])->save();
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Broken lamp',
      'request_id' => '42-2026',
      'status' => 1,
    ]);
    $url = 'https://reports.example.org/requests/42-2026';

    $published = $this->renderNotification($node, $notificationKey);
    $this->assertStringContainsString('href="' . $url . '"', $published['body'][0]);
    $this->assertStringContainsString('View your report: ' . $url, $published['params']['_plain_alt']);

    $node->setUnpublished();
    $unpublished = $this->renderNotification($node, $notificationKey);
    $this->assertSame($published['subject'], $unpublished['subject']);
    $this->assertSame($published['to'], $unpublished['to']);
    $publishedHtml = new \DOMDocument();
    $publishedHtml->loadHTML($published['body'][0], LIBXML_NOERROR | LIBXML_NOWARNING);
    $unpublishedHtml = new \DOMDocument();
    $unpublishedHtml->loadHTML($unpublished['body'][0], LIBXML_NOERROR | LIBXML_NOWARNING);
    $publishedText = preg_replace('/\s+/', ' ', $publishedHtml->textContent);
    $unpublishedText = preg_replace('/\s+/', ' ', $unpublishedHtml->textContent);
    $this->assertSame(
      trim(str_replace('View your report ', '', $publishedText)),
      trim($unpublishedText),
    );
    foreach ([$unpublished['body'][0], $unpublished['params']['_plain_alt']] as $body) {
      $this->assertStringContainsString('Your report was updated', $body);
      $this->assertStringContainsString('We are processing your report.', $body);
      $this->assertStringContainsString('Thank you for your report.', $body);
      $this->assertStringNotContainsString('View your report', $body);
      $this->assertStringNotContainsString($url, $body);
      $this->assertStringNotContainsString('/requests/', $body);
    }
    $this->assertSame(
      str_replace('View your report: ' . $url . "\n\n", '', $published['params']['_plain_alt']),
      $unpublished['params']['_plain_alt'],
    );

    // An already rendered mail remains linkless after later publication.
    $node->setPublished();
    $republished = $this->renderNotification($node, $notificationKey);
    $this->assertSame($published['body'], $republished['body']);
    $this->assertSame($published['params']['_plain_alt'], $republished['params']['_plain_alt']);
    $this->assertStringNotContainsString('/requests/', $unpublished['body'][0]);
  }

  /**
   * Provides every shipped config-driven citizen notification.
   */
  public static function notificationKeys(): array {
    return [
      'confirmation' => ['report_confirmation'],
      'open' => ['status_open'],
      'closed' => ['status_closed'],
      'not responsible' => ['status_not_responsible'],
    ];
  }

  /**
   * Runs the fallback hook and real branded rendering without sending mail.
   *
   * @return array<string, mixed>
   *   The rendered Drupal mail message.
   */
  private function renderNotification(NodeInterface $node, string $notificationKey): array {
    $message = [
      'id' => 'markaspot_mail_notification_' . $notificationKey,
      'module' => 'markaspot_mail',
      'key' => 'notification_' . $notificationKey,
      'to' => 'citizen@example.org',
      'langcode' => 'en',
      'params' => ['node' => $node, 'notification_key' => $notificationKey],
      'subject' => '',
      'body' => [],
      'headers' => [],
    ];
    markaspot_mail_mail($message['key'], $message, $message['params']);
    $this->container->get('markaspot_mail.mail_alter_hook')->alter($message);
    $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type'] ?? NULL);
    return $message;
  }

}
