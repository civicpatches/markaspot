<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\PageCache\RequestPolicyInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_nuxt\Logging\CorrelationIdProvider;
use Drupal\markaspot_nuxt\Logging\CorrelationJsonFormatter;
use Drupal\markaspot_nuxt\MarkaspotNuxtServiceProvider;
use Drush\Drupal\DrushLoggerServiceProvider;
use Drush\Drush;
use Monolog\Handler\StreamHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Exercises the shipped logging services with real Monolog, HTTP and dblog.
 */
#[Group('markaspot_nuxt')]
#[RunTestsInSeparateProcesses]
final class CorrelationLoggingKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'dblog', 'monolog'];

  /**
   * Enables a site-style alternate channel in the handler isolation test.
   */
  private bool $alternateChannel = FALSE;

  /**
   * Loads the shipped logging definitions without unrelated Nuxt services.
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $services = Yaml::decode(file_get_contents(dirname(__DIR__, 3) . '/markaspot_nuxt.services.yml'))['services'];
    foreach ($services as $id => $service) {
      if (!str_contains($id, 'correlation')) {
        continue;
      }
      $arguments = array_map(static fn($value) => is_string($value) && str_starts_with($value, '@') ? new Reference(substr($value, 1)) : $value, $service['arguments']);
      $definition = $container->register($id, $service['class'])
        ->setArguments($arguments)->setShared($service['shared'] ?? TRUE);
      foreach ($service['tags'] ?? [] as $tag) {
        $name = $tag['name'];
        unset($tag['name']);
        $definition->addTag($name, $tag);
      }
    }
    (new MarkaspotNuxtServiceProvider())->register($container);
    // Register after the defaults, as runtime providers such as Drush do.
    $container->register('logger.correlation_test', CorrelationTestLogger::class)
      ->addTag('logger', ['priority' => 100]);
    (new DrushLoggerServiceProvider())->register($container);
    if ($this->alternateChannel) {
      $channels = $container->getParameter('monolog.channel_handlers');
      $channels['alternate'] = [
        'handlers' => [['name' => 'correlation_stderr', 'formatter' => 'line', 'processors' => []]],
      ];
      $container->setParameter('monolog.channel_handlers', $channels);
    }
    // Only the destination differs; the real handler and formatter are used.
    $container->getDefinition('monolog.handler.correlation_stderr')->replaceArgument(0, 'php://memory');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system', 'user']);
    // KernelTestBase leaves a synthetic request on the stack. Model a fresh
    // HTTP lifecycle (or CLI) instead of treating that fixture as the main one.
    $stack = $this->container->get('request_stack');
    while ($stack->pop() !== NULL) {
    }
  }

  /**
   * Early logs, subrequests and both sinks share the response header's UUID.
   */
  public function testHttpCorrelation(): void {
    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->addListener(KernelEvents::REQUEST, function (RequestEvent $event): void {
      // Resolve before ordinary request subscribers or the controller run.
      $this->container->get('logger.factory')->get('php')->warning('Early @count', ['@count' => 3]);
      if ($event->isMainRequest()) {
        $sub = Request::create('/sub');
        $sub->headers->set('X-Request-Id', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
        $sub->attributes->set('_controller', static fn() => new Response('sub'));
        $response = $event->getKernel()->handle($sub, $event->getKernel()::SUB_REQUEST);
        $this->assertSame($this->container->get('markaspot_nuxt.correlation_id')->getId(), $response->headers->get('X-Request-Id'));
      }
      $event->setResponse(new Response('ok'));
    }, 2048);

    $ids = [];
    foreach (['01234567-89ab-4cde-8fab-0123456789ab', NULL, 'invalid-header'] as $header) {
      $request = Request::create('/correlation');
      if ($header !== NULL) {
        $request->headers->set('X-Request-Id', $header);
      }
      $response = $this->container->get('http_kernel')->handle($request);
      $this->assertSame(200, $response->getStatusCode());
      $id = $response->headers->get('X-Request-Id');
      $this->assertTrue(CorrelationIdProvider::isValid($id));
      $ids[] = $id;
      $this->container->get('kernel')->terminate($request, $response);
    }
    $this->assertSame('01234567-89ab-4cde-8fab-0123456789ab', $ids[0]);
    $this->assertCount(3, array_unique($ids));
    $this->assertNull($this->container->get('markaspot_nuxt.correlation_id')->getId());

    $lines = $this->readLines();
    $this->assertCount(6, $lines);
    $rows = $this->container->get('database')->select('watchdog', 'w')->fields('w')->orderBy('wid')->execute()->fetchAll();
    $this->assertCount(6, $rows);
    foreach ($lines as $index => $line) {
      $this->assertSame($ids[intdiv($index, 2)], $line['request_id']);
      $this->assertSame('Early 3', $line['message']);
      $this->assertSame('php', $line['channel']);
      $this->assertSame('Early @count', $rows[$index]->message);
      $variables = unserialize($rows[$index]->variables, ['allowed_classes' => FALSE]);
      $this->assertSame($line['request_id'], $variables['@correlation_id']);
      $this->assertSame(3, $variables['@count']);
    }
  }

  /**
   * Tagged sinks and Drush retain the original report ID and message template.
   */
  public function testExistingLoggersAndDrushOutput(): void {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
    $drush_container = new ContainerBuilder();
    $drush_container->set('logger', new ConsoleLogger($output));
    Drush::setContainer($drush_container);
    try {
      $request = Request::create('/report');
      $request->headers->set('X-Request-Id', '01234567-89ab-4cde-8fab-0123456789ab');
      $this->container->get('request_stack')->push($request);
      $this->container->get('logger.factory')->get('report')->warning('Updated %request_id', [
        'request_id' => '2026-0042', '@request_id' => '2026-0042',
        '%request_id' => '2026-0042',
      ]);
      $entries = $this->container->get('logger.correlation_test')->entries;
      $this->assertCount(1, $entries);
      $this->assertSame('Updated %request_id', $entries[0]['message']);
      foreach (['request_id', '@request_id', '%request_id'] as $key) {
        $this->assertSame('2026-0042', $entries[0]['context'][$key]);
      }
      $this->assertSame('01234567-89ab-4cde-8fab-0123456789ab', $entries[0]['context']['@correlation_id']);
      $this->assertStringContainsString('Updated 2026-0042', $output->fetch());
      $line = $this->readLines()[0];
      $this->assertSame('01234567-89ab-4cde-8fab-0123456789ab', $line['request_id']);
      $this->assertSame('Updated [redacted]', $line['message']);
      $row = $this->container->get('database')->select('watchdog', 'w')->fields('w')->execute()->fetchObject();
      $variables = unserialize($row->variables, ['allowed_classes' => FALSE]);
      $this->assertSame('2026-0042', $variables['%request_id']);
      $this->assertSame($line['request_id'], $variables['@correlation_id']);
    }
    finally {
      $this->container->get('request_stack')->pop();
      Drush::unsetContainer();
    }
  }

  /**
   * A database failure must not prevent writing the JSON record first.
   */
  public function testStderrPrecedesDblogAndCliIsNull(): void {
    $this->container->get('database')->schema()->dropTable('watchdog');
    $error = NULL;
    try {
      $this->container->get('logger.factory')->get('custom_channel')->error('Database unavailable');
    }
    catch (\Exception $exception) {
      $error = $exception;
    }
    $this->assertInstanceOf(DatabaseException::class, $error);
    $lines = $this->readLines();
    $this->assertCount(1, $lines);
    $this->assertSame('Database unavailable', $lines[0]['message']);
    $this->assertNull($lines[0]['request_id']);
  }

  /**
   * The real update installs Monolog on an existing site and is idempotent.
   */
  public function testUpdateEnablesMonolog(): void {
    require_once dirname(__DIR__, 3) . '/markaspot_nuxt.install';
    $this->disableModules(['monolog']);
    $this->assertFalse($this->container->get('module_handler')->moduleExists('monolog'));
    markaspot_nuxt_update_11921();
    $this->container = \Drupal::getContainer();
    $this->assertTrue($this->container->get('module_handler')->moduleExists('monolog'));
    $this->assertArrayHasKey('monolog', $this->config('core.extension')->get('module'));
    markaspot_nuxt_update_11921();
    $this->container->get('logger.factory')->get('update_test')->info('Updated');
    $lines = $this->readLines();
    $this->assertSame('Updated', $lines[array_key_last($lines)]['message']);
  }

  /**
   * Real Internal Page Cache hits replace the cached MISS header.
   */
  public function testPageCacheHitHasItsOwnHeader(): void {
    $this->enableModules(['page_cache']);
    // Enable cache reads under CLI, which the normal request policy excludes.
    $policy = $this->createMock(RequestPolicyInterface::class);
    $policy->method('check')->willReturn(RequestPolicyInterface::ALLOW);
    $this->container->set('page_cache_request_policy', $policy);
    $calls = 0;
    $this->container->get('event_dispatcher')->addListener(KernelEvents::REQUEST, function (RequestEvent $event) use (&$calls): void {
      $calls++;
      $this->container->get('logger.factory')->get('cache_test')->info('Cache miss');
      $event->setResponse(new CacheableResponse('cached body'));
    }, 2048);

    $headers = [];
    foreach (['MISS', 'HIT', 'HIT'] as $expected) {
      $request = Request::create('/cache-correlation');
      $response = $this->container->get('http_kernel')->handle($request);
      $this->assertSame($expected, $response->headers->get('X-Drupal-Cache'));
      $this->assertSame('cached body', $response->getContent());
      $headers[] = $response->headers->get('X-Request-Id');
      $this->assertTrue(CorrelationIdProvider::isValid(end($headers)));
      $this->container->get('kernel')->terminate($request, $response);
    }
    $this->assertSame(1, $calls);
    $this->assertCount(3, array_unique($headers));
    $this->assertSame($headers[0], $this->readLines()[0]['request_id']);
  }

  /**
   * A site's alternate formatter cannot mutate another channel's handler.
   */
  public function testSiteChannelHandlersAreIsolated(): void {
    $this->alternateChannel = TRUE;
    $this->container->get('kernel')->rebuildContainer();
    $this->container = \Drupal::getContainer();
    $factory = $this->container->get('logger.factory');
    $default = $factory->get('php')->getAdaptedLogger()->getHandlers()[0];
    $alternate = $factory->get('alternate')->getAdaptedLogger()->getHandlers()[0];
    $this->assertNotSame($default, $alternate);
    $this->assertInstanceOf(CorrelationJsonFormatter::class, $default->getFormatter());
    $factory->get('alternate')->info('Alternate format');
    $factory->get('php')->info('Default format');
    $this->assertSame('Default format', $this->readLines()[0]['message']);
  }

  /**
   * Returns the JSON records actually written by the stream handler.
   */
  private function readLines(): array {
    $handlers = $this->container->get('logger.factory')->get('php')->getAdaptedLogger()->getHandlers();
    $this->assertInstanceOf(StreamHandler::class, $handlers[0]);
    $stream = $handlers[0]->getStream();
    $this->assertIsResource($stream);
    rewind($stream);
    return array_map(static fn(string $line) => json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR), explode("\n", rtrim(stream_get_contents($stream), "\n")));
  }

}
