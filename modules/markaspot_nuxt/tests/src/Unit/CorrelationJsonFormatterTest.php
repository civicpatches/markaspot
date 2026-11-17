<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_nuxt\Unit;

use Drupal\Core\Logger\LogMessageParser;
use Drupal\markaspot_nuxt\Logging\CorrelationJsonFormatter;
use Drupal\Tests\UnitTestCase;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the JSON contract, interpolation and sensitive-data exclusions.
 */
#[Group('markaspot_nuxt')]
final class CorrelationJsonFormatterTest extends UnitTestCase {

  /**
   * The line has exactly the contract keys and safely escapes newlines.
   */
  public function testJsonLine(): void {
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    $record = new LogRecord(
      new \DateTimeImmutable('2026-11-17T12:34:56.123456+02:00'),
      'php', Level::Warning, "Quoted \"@name\"\n{count} café \\ end",
      [
        '@name' => 'example', 'count' => 3,
        'request_id' => '2026-0042',
        'correlation_id' => '01234567-89ab-4cde-8fab-0123456789ab',
        '@correlation_id' => '01234567-89ab-4cde-8fab-0123456789ab',
        'user_agent' => 'private-agent', 'ip' => '192.0.2.12',
        'cookies' => ['session' => 'private-cookie'],
        'token' => 'private-token', 'email' => 'private@example.test',
        'body' => 'private-body', 'exception' => new \RuntimeException('private-exception'),
      ],
      ['private_extra' => 'private-extra'],
    );
    $line = $formatter->format($record);
    $this->assertSame(1, substr_count($line, "\n"));
    $this->assertStringEndsWith("\n", $line);
    $data = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame([
      'timestamp' => '2026-11-17T10:34:56.123456Z',
      'service' => 'drupal',
      'request_id' => '01234567-89ab-4cde-8fab-0123456789ab',
      'channel' => 'php',
      'level' => 'warning',
      'message' => "Quoted \"[redacted]\"\n3 café \\ end",
    ], $data);
    $this->assertStringNotContainsString('private-', $line);
    $this->assertStringNotContainsString('192.0.2.12', $line);
    $this->assertSame("Quoted \"@name\"\n{count} café \\ end", $record->message);
  }

  /**
   * Sensitive placeholders and common literals cannot leak into the message.
   */
  public function testSensitiveMessageValues(): void {
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Error,
      'Failure @email @ip @token @body @user_agent private@example.test 192.0.2.12 2001:db8::1 https://example.test/?secret=private Bearer private-token',
      [
        '@email' => 'secret@example.test', '@ip' => '192.0.2.13',
        '@token' => 'secret-token', '@body' => 'secret-body',
        '@user_agent' => 'secret-agent',
      ],
    );
    $line = $formatter->format($record);
    foreach (['secret', 'private', '192.0.2', '2001:db8', 'example.test'] as $forbidden) {
      $this->assertStringNotContainsString($forbidden, $line);
    }
    $this->assertNull(json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR)['request_id']);
  }

  /**
   * Each batch record remains one line, with valid JSON for malformed UTF-8.
   */
  public function testBatchAndLevels(): void {
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    $records = [];
    foreach (Level::cases() as $level) {
      $records[] = new LogRecord(new \DateTimeImmutable(), 'test', $level, "Bad UTF-8 \xFF", ['correlation_id' => 'invalid']);
    }
    $lines = explode("\n", rtrim($formatter->formatBatch($records), "\n"));
    $this->assertCount(count(Level::cases()), $lines);
    foreach ($lines as $index => $line) {
      $data = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame(strtolower(Level::cases()[$index]->getName()), $data['level']);
      $this->assertNull($data['request_id']);
    }
  }

  /**
   * Generic placeholders can hold citizen content or quoted upstream errors.
   */
  public function testUntrustedScalarPlaceholdersAreRedacted(): void {
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    $record = new LogRecord(new \DateTimeImmutable(), 'validation', Level::Error,
      'Invalid @value: @message; @count entries; @duration seconds; @status status',
      [
        '@value' => 'My neighbour broke my fence',
        '@message' => 'Provider quoted citizen content', '@count' => 3,
        '@duration' => 1.5, '@status' => 'arbitrary citizen content',
      ],
    );
    $message = json_decode($formatter->format($record), TRUE, 512, JSON_THROW_ON_ERROR)['message'];
    $this->assertSame('Invalid [redacted]: [redacted]; 3 entries; 1.5 seconds; [redacted] status', $message);
  }

  /**
   * Exception diagnostics preserve code locations, never messages or traces.
   */
  public function testExceptionCodeLocation(): void {
    $record = new LogRecord(new \DateTimeImmutable(), 'php', Level::Error,
      '%type: @message (%message) in %function (line %line of %file). @backtrace_string',
      [
        '%type' => 'Drupal\\Core\\Database\\DatabaseExceptionWrapper',
        '%function' => 'Drupal\\example\\Repository->save()',
        '%file' => '/var/www/html/modules/example/src/Repository.php',
        '%line' => 42,
        '@message' => 'SQL query with private citizen value',
        '%message' => 'Another private citizen value',
        '@backtrace_string' => 'private trace with arguments',
      ],
    );
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    $data = json_decode($formatter->format($record), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame('Drupal\\Core\\Database\\DatabaseExceptionWrapper: [redacted] ([redacted]) in Drupal\\example\\Repository->save() (line 42 of /var/www/html/modules/example/src/Repository.php). [redacted]', $data['message']);
  }

  /**
   * Credential names are caught after underscores and hyphens as well.
   */
  #[DataProvider('credentialNames')]
  public function testCredentialLiterals(string $name): void {
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    foreach (['=', ': '] as $separator) {
      $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Error, 'Failed: ' . $name . $separator . 'credential-value');
      $line = $formatter->format($record);
      $this->assertStringNotContainsString('credential-value', $line);
      $this->assertStringContainsString('[redacted]', $line);
    }
  }

  /**
   * Credential labels seen in headers, query strings and provider messages.
   */
  public static function credentialNames(): array {
    $names = [
      'token', 'access_token', 'login_token', 'client_secret', 'api_key', 'apikey',
      'x-api-key', 'pass', 'password', 'secret', 'cookie', 'authorization',
      'user-agent', 'access-token', 'client-secret',
    ];
    return array_combine($names, array_map(static fn(string $name): array => [$name], $names));
  }

  /**
   * Long messages are capped by bytes without splitting Unicode characters.
   */
  public function testMessageLengthCap(): void {
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    foreach ([str_repeat('a', 16384), str_repeat('a', 16383) . 'é'] as $prefix) {
      $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Error, $prefix . str_repeat('b', 100000));
      $data = json_decode($formatter->format($record), TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertSame(mb_strcut($prefix, 0, 16384, 'UTF-8') . '…[truncated]', $data['message']);
    }
    $exact = new LogRecord(new \DateTimeImmutable(), 'test', Level::Error, str_repeat('a', 16384));
    $this->assertSame(str_repeat('a', 16384), json_decode($formatter->format($exact), TRUE, 512, JSON_THROW_ON_ERROR)['message']);
  }

  /**
   * Escaping and oversized channel names cannot exceed FPM's 64 KiB limit.
   */
  public function testEscapedLineLengthCap(): void {
    $formatter = new CorrelationJsonFormatter(new LogMessageParser());
    foreach (["\x01", "\xFF", "\n", 'é'] as $character) {
      $record = new LogRecord(new \DateTimeImmutable(), str_repeat($character, 100000), Level::Error, str_repeat($character, 100000));
      $line = $formatter->format($record);
      $this->assertLessThan(65536, strlen($line));
      $data = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
      $this->assertStringEndsWith('…[truncated]', $data['message']);
      $this->assertStringEndsWith('…[truncated]', $data['channel']);
    }
  }

}
