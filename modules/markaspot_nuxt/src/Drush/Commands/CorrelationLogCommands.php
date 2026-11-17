<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Render\HtmlEscapedText;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\dblog\Controller\DbLogController;
use Drupal\markaspot_nuxt\Logging\CorrelationIdProvider;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finds database log entries belonging to one HTTP request.
 */
final class CorrelationLogCommands extends DrushCommands {

  /**
   * Constructs the command.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ClassResolverInterface $classResolver,
    private readonly string $timezone,
  ) {
    parent::__construct();
  }

  /**
   * Instantiates the command via Drush discovery.
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('class_resolver'),
      $container->get('config.factory')->get('system.date')->get('timezone.default') ?: 'UTC',
    );
  }

  /**
   * Shows at most 200 matching entries, oldest first.
   *
   * @param string $id
   *   A full UUID v4 or its first eight lowercase hexadecimal characters.
   * @param array<string, mixed> $options
   *   Inclusive time bounds, parsed by strtotime().
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   Log entries with plain-text messages formatted from dblog placeholders.
   */
  #[CLI\Command(name: 'markaspot:log', aliases: ['mas-log'])]
  #[CLI\Argument(name: 'id', description: 'A full UUID v4 or eight lowercase hex characters.')]
  #[CLI\Option(name: 'since', description: 'Earliest entry (strtotime string; default: 7 days ago).')]
  #[CLI\Option(name: 'until', description: 'Latest entry (strtotime string; default: now).')]
  #[CLI\FieldLabels(labels: [
    'time' => 'Time',
    'type' => 'Type',
    'severity' => 'Severity',
    'uid' => 'UID',
    'hostname' => 'Hostname',
    'location' => 'Location',
    'message' => 'Message',
  ])]
  #[CLI\DefaultTableFields(fields: ['time', 'type', 'severity', 'uid', 'hostname', 'location', 'message'])]
  #[CLI\Usage(name: 'mas-log b31c8c3b --since="2 days ago" --format=json', description: 'Find recent entries by request ID prefix.')]
  public function lookup(string $id, array $options = ['since' => '-7 days', 'until' => 'now']): RowsOfFields {
    if (!CorrelationIdProvider::isValid($id) && preg_match('/\A[0-9a-f]{8}\z/', $id) !== 1) {
      throw new \InvalidArgumentException('ID must be a full UUID v4 or exactly eight lowercase hexadecimal characters.', 1);
    }
    $id = strtolower($id);
    $now = time();
    $since = strtotime((string) ($options['since'] ?? '-7 days'), $now);
    $until = strtotime((string) ($options['until'] ?? 'now'), $now);
    if ($since === FALSE || $until === FALSE || $since > $until) {
      throw new \InvalidArgumentException('Invalid time window: since and until must be valid dates, with since <= until.', 1);
    }
    if (!$this->database->schema()->tableExists('watchdog')) {
      throw new \RuntimeException('Database logging is unavailable: enable the dblog module.', 1);
    }

    // Narrow the scan by the serialized key/value pair, then verify the actual
    // top-level variable. IDs in messages or other variables are not matches.
    $needle = serialize('@correlation_id') . 's:36:"' . $id;
    if (strlen($id) === 36) {
      $needle .= '";';
    }
    $pattern = '%' . $this->database->escapeLike($needle) . '%';
    $formatter = $this->classResolver->getInstanceFromDefinition(DbLogController::class);
    $levels = RfcLogLevel::getLevels();
    $rows = [];
    $offset = 0;
    do {
      $entries = $this->database->select('watchdog', 'w')
        ->fields('w')
        ->condition('timestamp', [$since, $until], 'BETWEEN')
        ->condition('variables', $pattern, 'LIKE')
        ->orderBy('timestamp')->orderBy('wid')
        ->range($offset, 200)->execute()->fetchAll();
      foreach ($entries as $entry) {
        $variables = @unserialize($entry->variables, ['allowed_classes' => FALSE]);
        $correlationId = is_array($variables) ? ($variables['@correlation_id'] ?? NULL) : NULL;
        if (!CorrelationIdProvider::isValid($correlationId) || !str_starts_with(strtolower($correlationId), $id)) {
          continue;
        }
        // Core deserializes again. Rebuild only known Core markup values from
        // inert data; never pass an object supplied by the serialized log.
        $entry->variables = serialize(array_map($this->safeVariable(...), $variables));
        $rows[] = [
          'time' => $this->dateFormatter->format((int) $entry->timestamp, 'custom', 'Y-m-d H:i:s T', $this->timezone),
          'type' => $entry->type,
          'severity' => (string) ($levels[$entry->severity] ?? $entry->severity),
          'uid' => (int) $entry->uid,
          'hostname' => $entry->hostname,
          'location' => $entry->location,
          'message' => preg_replace('/\s+/', ' ', Html::decodeEntities(strip_tags((string) $formatter->formatMessage($entry)))),
        ];
        if (count($rows) === 200) {
          break;
        }
      }
      $offset += 200;
    } while (count($entries) === 200 && count($rows) < 200);

    return new RowsOfFields($rows);
  }

  /**
   * Reconstructs Core's string placeholders without loading serialized classes.
   *
   * Unknown objects and recursive data cannot safely be rendered as strings.
   */
  private function safeVariable(mixed $value, int $depth = 0): mixed {
    if (is_scalar($value) || $value === NULL) {
      return $value;
    }
    if (!$value instanceof \__PHP_Incomplete_Class || $depth >= 20) {
      return '[Invalid log variable]';
    }
    $data = (array) $value;
    $class = $data['__PHP_Incomplete_Class_Name'];
    $string = $data["\0*\0string"] ?? NULL;
    if (!is_string($string)) {
      return '[Invalid log variable]';
    }
    if ($class === Markup::class) {
      return Markup::create($string);
    }
    if ($class === HtmlEscapedText::class) {
      return new HtmlEscapedText($string);
    }
    if (!in_array($class, [FormattableMarkup::class, TranslatableMarkup::class, PluralTranslatableMarkup::class], TRUE)) {
      return '[Invalid log variable]';
    }
    $arguments = $data["\0*\0arguments"] ?? [];
    if (!is_array($arguments)) {
      return '[Invalid log variable]';
    }
    $arguments = array_map(fn($argument) => $this->safeVariable($argument, $depth + 1), $arguments);
    if ($class === FormattableMarkup::class) {
      return new FormattableMarkup($string, $arguments);
    }
    $options = $data["\0*\0options"] ?? [];
    $options = is_array($options) ? array_filter($options, is_string(...)) : [];
    if ($class === PluralTranslatableMarkup::class) {
      $count = $data["\0*\0count"] ?? NULL;
      if (!is_numeric($count)) {
        return '[Invalid log variable]';
      }
      $translated = $data["\0*\0translatedString"] ?? NULL;
      if (is_string($translated) && $translated !== '') {
        return PluralTranslatableMarkup::createFromTranslatedString($count, $translated, $arguments, $options);
      }
      [$singular, $plural] = array_pad(explode(PoItem::DELIMITER, $string, 2), 2, '');
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      return new PluralTranslatableMarkup($count, $singular, $plural, $arguments, $options);
    }
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return new TranslatableMarkup($string, $arguments, $options);
  }

}
