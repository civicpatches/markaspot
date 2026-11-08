<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\Group as TestGroup;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

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
   * Tests both buttons keep their hosts when staff send from the backend.
   *
   * A staff member saving the request at the staff backend host makes it
   * the request host, which URL normalization does not trust. The backend
   * link built from the configured staff base must still reach staff on
   * that host, not on the public asset origin (here the frontend host).
   */
  public function testButtonsKeepTheStaffBackendHostWhenSentFromIt(): void {
    $this->staffBackendSettings();
    $this->pushRequest('https://management.example.org/node/4711/edit');

    $message = $this->alterOrganisationMail($this->node(4711));

    $html = $message['body'][0];
    $plain = $message['params']['_plain_alt'];
    $this->assertStringContainsString('href="https://melder.example.org/bonn/dashboard/requests/4711/edit"', $html);
    $this->assertStringContainsString('href="https://management.example.org/node/4711/edit"', $html);
    $this->assertStringNotContainsString('melder.example.org/node/4711', $html);
    $this->assertStringContainsString('Open request: https://melder.example.org/bonn/dashboard/requests/4711/edit', $plain);
    $this->assertStringContainsString('Open in the backend: https://management.example.org/node/4711/edit', $plain);
    $this->assertStringNotContainsString('melder.example.org/node/4711', $plain);
  }

  /**
   * Tests token links stay and other request-host URLs are still rebased.
   *
   * Covers an unbranded mail body as ECA writes it, with the backend token
   * expanded, a file URL and a password reset URL on the request host.
   */
  public function testTokenLinkStaysAndOtherRequestHostUrlsAreRebased(): void {
    $this->staffBackendSettings();
    $this->pushRequest('https://management.example.org/node/4711/edit');
    $edit = (string) $this->container->get('token')->replace('[node:markaspot_backend_edit_url]', ['node' => $this->node(4711)], ['langcode' => 'en']);
    $this->assertSame('https://management.example.org/node/4711/edit', $edit);

    $message = $this->alterPlainMail("Edit: $edit.\nPhoto: https://management.example.org/sites/default/files/photo.jpg\nReset: https://management.example.org/user/reset/1/2/abc");

    $this->assertSame(
      ["Edit: https://management.example.org/node/4711/edit.\nPhoto: https://melder.example.org/sites/default/files/photo.jpg\nReset: https://melder.example.org/user/reset/1/2/abc"],
      $message['body'],
    );
  }

  /**
   * Tests a poisoned request host is rebased even in the backend link shape.
   */
  public function testUntrustedRequestHostIsStillRebased(): void {
    $this->staffBackendSettings();
    $this->pushRequest('https://attacker.example.net/');

    $message = $this->alterPlainMail('Edit: https://attacker.example.net/node/4711/edit Real: https://management.example.org/node/4711/edit');

    $this->assertSame(
      ['Edit: https://melder.example.org/node/4711/edit Real: https://management.example.org/node/4711/edit'],
      $message['body'],
    );
  }

  /**
   * Configures a frontend asset origin and a separate staff backend host.
   */
  private function staffBackendSettings(): void {
    $this->mailSettings([
      'frontend_base_url' => 'https://melder.example.org',
      'backend_base_url' => 'https://melder.example.org',
      'staff_backend_base_url' => 'https://management.example.org',
    ], 'both');
  }

  /**
   * Makes a request to the given URL the current request.
   */
  private function pushRequest(string $url): void {
    $requestStack = $this->container->get('request_stack');
    $request = Request::create($url);
    $request->setSession($requestStack->getSession());
    $requestStack->push($request);
  }

  /**
   * Runs the alter hook over a plain mail that no builder brands.
   *
   * @return array<string, mixed>
   *   The altered message.
   */
  private function alterPlainMail(string $body): array {
    $message = [
      'id' => 'markaspot_mail_kernel_test_plain',
      'module' => 'markaspot_mail_kernel_test',
      'key' => 'plain',
      'to' => 'staff@example.org',
      'langcode' => 'en',
      'params' => [],
      'subject' => 'Request',
      'body' => [$body],
      'headers' => [],
    ];
    $this->container->get('markaspot_mail.mail_alter_hook')->alter($message);
    return $message;
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
