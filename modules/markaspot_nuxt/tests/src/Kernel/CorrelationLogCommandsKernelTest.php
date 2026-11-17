<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Consolidation\AnnotatedCommand\AnnotatedCommandFactory;
use Consolidation\OutputFormatters\FormatterManager;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Render\HtmlEscapedText;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dblog\Controller\DbLogController;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_nuxt\Drush\Commands\CorrelationLogCommands;
use Drupal\markaspot_nuxt\Logging\CorrelationIdProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests correlation lookups against real database log entries and Drush output.
 */
#[Group('markaspot_nuxt')]
#[RunTestsInSeparateProcesses]
final class CorrelationLogCommandsKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'dblog'];

  private const FIRST = '01234567-89ab-4cde-8fab-0123456789ab';
  private const SECOND = 'fedcba98-7654-4321-8abc-fedcba987654';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system', 'user']);
    $this->config('system.date')->set('timezone.default', 'Europe/Berlin')->save();
  }

  /**
   * Two HTTP requests remain separate, including in formatted JSON output.
   */
  public function testLookup(): void {
    $stack = $this->container->get('request_stack');
    while ($stack->pop() !== NULL) {
    }
    $provider = new CorrelationIdProvider($stack, $this->container->get('uuid'));
    foreach ([self::FIRST, self::SECOND] as $id) {
      $request = Request::create('https://example.test/request/' . $id);
      $request->headers->set('X-Request-Id', $id);
      $stack->push($request);
      $this->container->get('logger.dblog')->log(4, 'Request @name: %value @backtrace_string', [
        '@correlation_id' => $provider->getId(),
        '@name' => '<citizen>',
        '%value' => 'quoted',
        '@backtrace_string' => 'frame <one>',
        'uid' => 17,
        'channel' => 'request-test',
        'request_uri' => $request->getUri(),
        'referer' => '',
        'ip' => '192.0.2.1',
        'timestamp' => time(),
        'link' => '',
      ]);
      $stack->pop();
    }
    // A matching ID elsewhere, or below the top level, is not correlation.
    $this->insert(['@other' => self::FIRST]);
    $this->insert(['nested' => ['@correlation_id' => self::FIRST]]);

    $command = CorrelationLogCommands::create($this->container);
    $full = $command->lookup(self::FIRST)->getArrayCopy();
    $this->assertSame($full, $command->lookup('01234567')->getArrayCopy());
    $this->assertSame($full, $command->lookup(strtoupper(self::FIRST))->getArrayCopy());
    $this->assertCount(1, $full);
    $this->assertSame('request-test', $full[0]['type']);
    $this->assertSame('Warning', $full[0]['severity']);
    $this->assertSame(17, $full[0]['uid']);
    $this->assertSame('192.0.2.1', $full[0]['hostname']);
    $this->assertStringEndsWith(self::FIRST, $full[0]['location']);
    $this->assertMatchesRegularExpression('/ (CET|CEST)$/', $full[0]['time']);
    $this->assertSame('Request &lt;citizen&gt;: <em class="placeholder">quoted</em> <pre class="backtrace">frame &lt;one&gt;</pre>', $full[0]['message']);
    $this->assertCount(1, $command->lookup(self::SECOND)->getArrayCopy());

    $tester = $this->application();
    $this->assertSame(0, $tester->run(['command' => 'mas-log', 'id' => '01234567', '--format' => 'json']));
    $this->assertSame($full, json_decode($tester->getDisplay(), TRUE, 512, JSON_THROW_ON_ERROR));
  }

  /**
   * Defaults exclude old and future rows; explicit bounds include endpoints.
   */
  public function testTimeWindow(): void {
    $now = time();
    $this->insert(['@correlation_id' => self::FIRST], $now - 8 * 86400, 'old');
    $this->insert(['@correlation_id' => self::FIRST], $now - 60, 'recent');
    $this->insert(['@correlation_id' => self::FIRST], $now + 86400, 'future');
    $command = CorrelationLogCommands::create($this->container);
    $this->assertSame(['recent'], array_column($command->lookup(self::FIRST)->getArrayCopy(), 'message'));
    $rows = $command->lookup(self::FIRST, ['since' => '@' . ($now - 8 * 86400), 'until' => '@' . ($now - 60)])->getArrayCopy();
    $this->assertSame(['old', 'recent'], array_column($rows, 'message'));
  }

  /**
   * False candidates cannot consume the cap; results stop at 200 oldest rows.
   */
  public function testLimitAndSafeVariables(): void {
    $now = time();
    for ($i = 0; $i < 201; $i++) {
      $this->insert(['nested' => ['@correlation_id' => self::FIRST]], $now - 10);
    }
    for ($i = 0; $i < 205; $i++) {
      $this->insert(['@correlation_id' => self::FIRST], $now - 1, (string) $i);
    }
    $command = CorrelationLogCommands::create($this->container);
    $rows = $command->lookup(self::FIRST)->getArrayCopy();
    $this->assertCount(200, $rows);
    $this->assertSame('0', $rows[0]['message']);
    $this->assertSame('199', $rows[199]['message']);
    $this->container->get('database')->truncate('watchdog')->execute();
    $this->insert(['@correlation_id' => self::FIRST, '@object' => new \stdClass()], $now - 1, '@object');
    $this->assertSame('[Invalid log variable]', $command->lookup(self::FIRST)->getArrayCopy()[0]['message']);
  }

  /**
   * Core Stringable placeholders retain dblog's markup and escaping semantics.
   */
  public function testCoreMarkupVariables(): void {
    $values = [
      new FormattableMarkup('Label @name', ['@name' => '<name>']),
      new TranslatableMarkup('Label @name', ['@name' => '<name>']),
      new PluralTranslatableMarkup(2, 'One item', '@count items'),
      Markup::create('<em>safe markup</em>'),
      new HtmlEscapedText('<unsafe>'),
      new FormattableMarkup('Nested @value', ['@value' => new TranslatableMarkup('Label @name', ['@name' => '<name>'])]),
    ];
    foreach ($values as $value) {
      $this->insert(['@correlation_id' => self::FIRST, '@value' => $value], NULL, '@value');
    }
    $formatter = $this->container->get('class_resolver')->getInstanceFromDefinition(DbLogController::class);
    $expected = [];
    foreach ($this->container->get('database')->select('watchdog', 'w')->fields('w')->orderBy('wid')->execute() as $entry) {
      $expected[] = (string) $formatter->formatMessage($entry);
    }
    $rows = CorrelationLogCommands::create($this->container)->lookup(self::FIRST)->getArrayCopy();
    $this->assertSame($expected, array_column($rows, 'message'));
  }

  /**
   * Invalid input exits through the command runner with status one.
   */
  #[DataProvider('invalidInputs')]
  public function testInvalidInput(string $id, array $options): void {
    $tester = $this->application();
    $this->assertSame(1, $tester->run(['command' => 'markaspot:log', 'id' => $id] + $options));
    $this->assertStringContainsString($options === [] ? 'ID must be' : 'Invalid time window', $tester->getDisplay());
  }

  /**
   * Invalid IDs, malformed dates and reversed bounds.
   */
  public static function invalidInputs(): array {
    return [
      ['0123456', []],
      ['012345678', []],
      ['ABCDEF01', []],
      [self::FIRST . "\n", []],
      ['01234567-89ab-1cde-8fab-0123456789ab', []],
      ['01234567-89ab-4cde-7fab-0123456789ab', []],
      [self::FIRST, ['--since' => 'not a date']],
      [self::FIRST, ['--until' => 'not a date']],
      [self::FIRST, ['--since' => 'tomorrow', '--until' => 'yesterday']],
    ];
  }

  /**
   * Builds the same attribute-based command and output formatter used by Drush.
   */
  private function application(): ApplicationTester {
    $factory = new AnnotatedCommandFactory();
    $formatters = new FormatterManager();
    $formatters->addDefaultFormatters();
    $factory->commandProcessor()->setFormatterManager($formatters);
    $application = new Application();
    $application->setAutoExit(FALSE);
    $commandFile = CorrelationLogCommands::create($this->container);
    $commandFile->setInput(new ArrayInput([]));
    $commandFile->setOutput(new BufferedOutput());
    foreach ($factory->createCommandsFromClass($commandFile, FALSE) as $command) {
      $application->addCommand($command);
    }
    return new ApplicationTester($application);
  }

  /**
   * Inserts an isolated database log fixture.
   */
  private function insert(array $variables, ?int $timestamp = NULL, string $message = 'fixture'): void {
    $this->container->get('database')->insert('watchdog')->fields([
      'type' => 'fixture',
      'message' => $message,
      'variables' => serialize($variables),
      'location' => 'https://example.test/fixture',
      'timestamp' => $timestamp ?? time(),
    ])->execute();
  }

}
