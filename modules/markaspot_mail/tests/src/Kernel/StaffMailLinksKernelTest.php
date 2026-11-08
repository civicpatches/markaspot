<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests staff mail links through the real mail alter pipeline.
 *
 * Runs the organisation notification through MailAlterHook with the real
 * builder, token service, branding service, URL normalization and Twig
 * rendering, so both mail parts are checked as they leave Drupal.
 */
#[TestGroup('markaspot_mail')]
#[RunTestsInSeparateProcesses]
final class StaffMailLinksKernelTest extends StaffLinkKernelTestBase {

  /**
   * Tests a token in the backend link label is inserted as plain text.
   *
   * The label is escaped exactly once in the HTML part and appears as is in
   * the text part; line breaks of the token value collapse to a space.
   */
  public function testBackendLinkLabelTokensAreEscapedOnce(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => 'https://management.example.org',
    ], 'both');
    $this->config('markaspot_mail.texts')
      ->set('group_assignment.backend_cta_label', 'Open [node:title] in the backend')
      ->save();

    $message = $this->alterOrganisationMail($this->node(4711, title: "Road & Park \"A\" <b>\nnext"));

    $html = $message['body'][0];
    $this->assertStringContainsString('Open Road &amp; Park &quot;A&quot; &lt;b&gt; next in the backend', $html);
    $this->assertStringNotContainsString('&amp;amp;', $html);
    $this->assertStringContainsString(
      'Open Road & Park "A" <b> next in the backend: https://management.example.org/node/4711/edit',
      $message['params']['_plain_alt'],
    );
  }

  /**
   * Builds the organisation notification of a node and runs the alter hook.
   *
   * @return array<string, mixed>
   *   The altered message.
   */
  private function alterOrganisationMail(NodeInterface $node): array {
    GroupType::create(['id' => 'org', 'label' => 'Organisation'])->save();
    $organisation = Group::create(['type' => 'org', 'label' => 'Road Works']);
    $organisation->save();

    $message = [
      'id' => 'markaspot_group_org_notification',
      'module' => 'markaspot_group',
      'key' => 'org_notification',
      'to' => 'staff@example.org',
      'langcode' => 'en',
      'params' => ['node' => $node, 'organisation' => $organisation],
      'subject' => '',
      'body' => [],
      'headers' => [],
    ];
    $this->loadMailTemplatesThroughModuleNamespace();
    $this->container->get('markaspot_mail.mail_alter_hook')->alter($message);

    $this->assertSame('text/html; charset=UTF-8', $message['headers']['Content-Type'] ?? NULL, 'The organisation mail was branded.');
    return $message;
  }

}
