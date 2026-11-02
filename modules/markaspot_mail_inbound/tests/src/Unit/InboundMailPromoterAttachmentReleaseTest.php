<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\InvalidStreamWrapperException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Utility\Token;
use Drupal\Tests\UnitTestCase;
use Drupal\file\FileInterface;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\InternalRemarkWriter;
use Drupal\markaspot_vision\Service\MediaIntakeScreening;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for the promoter's partial attachment-release branch (#482).
 *
 * Phase 1 test gap: when media creation throws for ONE of several staged
 * files, the sibling file must still be attached (and its reference released
 * on the mail later), while the failed file stays OUT of the released set so
 * its reference keeps it reachable for discard / uninstall cleanup. Also
 * covers the LOW carry-over: without field_request_media on the node, NO
 * media entity is created at all.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\InboundMailPromoter
 */
class InboundMailPromoterAttachmentReleaseTest extends UnitTestCase {

  /**
   * Publication calls on created media, in order.
   *
   * @var string[]
   */
  protected array $publicationCalls = [];

  /**
   * Media handed to the same screening queue as Open311 imports.
   *
   * @var array<int|string>
   */
  protected array $queuedMedia = [];

  /**
   * Builds a promoter whose media storage throws on selected file ids.
   *
   * @param array<int, \Drupal\file\FileInterface|null> $files
   *   File ids mapped to the file mock the storage returns (NULL = missing).
   * @param int[] $throwOnFids
   *   File ids whose media save() throws.
   * @param int $mediaCreations
   *   Receives the number of media create() calls (by reference).
   * @param string $storageFailure
   *   Optional directory or move failure to simulate.
   */
  protected function promoter(array $files, array $throwOnFids, int &$mediaCreations, string $storageFailure = ''): TestablePromoterForAttachments {
    $fileStorage = $this->createMock(EntityStorageInterface::class);
    $fileStorage->method('load')->willReturnCallback(
      static fn(int|string $fid) => $files[(int) $fid] ?? NULL
    );

    $mediaStorage = $this->createMock(EntityStorageInterface::class);
    $mediaStorage->method('create')->willReturnCallback(
      function (array $values) use ($throwOnFids, &$mediaCreations): MediaInterface {
        $mediaCreations++;
        $fid = (int) ($values['field_media_image']['target_id'] ?? 0);
        $media = $this->createMock(MediaInterface::class);
        $media->method('uuid')->willReturn('uuid-' . $fid);
        $media->method('setName')->willReturnSelf();
        $media->method('setPublished')->willReturnCallback(function () use ($media) {
          $this->publicationCalls[] = 'published';
          return $media;
        });
        $media->method('setUnpublished')->willReturnCallback(function () use ($media) {
          $this->publicationCalls[] = 'unpublished';
          return $media;
        });
        $media->method('id')->willReturn(1000 + $fid);
        if (in_array($fid, $throwOnFids, TRUE)) {
          $media->method('save')->willThrowException(new \RuntimeException('media save failed'));
        }
        else {
          $media->method('save')->willReturn(1);
        }
        return $media;
      }
    );

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnCallback(
      static fn(string $type): EntityStorageInterface => match ($type) {
        'file' => $fileStorage,
        'media' => $mediaStorage,
        default => throw new \LogicException('Unexpected storage ' . $type),
      }
    );

    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->with('media')->willReturn(TRUE);

    $entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    if ($storageFailure === 'directory exception') {
      $fileSystem->method('prepareDirectory')->willThrowException(new InvalidStreamWrapperException('Missing private wrapper.'));
    }
    else {
      $fileSystem->method('prepareDirectory')->willReturn($storageFailure !== 'directory false');
    }
    $fileSystem->expects(str_starts_with($storageFailure, 'directory') ? $this->never() : ($storageFailure === 'file save exception' ? $this->exactly(2) : $this->any()))
      ->method('move')->willReturnCallback(static function ($source, $destination, $mode) use ($storageFailure) {
        if ($storageFailure === 'move exception') {
          throw new FileException('Private move failed.');
        }
        if ($storageFailure === 'file save exception' && str_starts_with($source, 'private://')) {
          self::assertSame('public://mail-inbound-staged/mail-7.jpg', $destination);
          self::assertSame(FileExists::Error, $mode);
        }
        return $storageFailure === 'move false' ? FALSE : $destination;
      });
    $logger = $this->createMock(LoggerChannelInterface::class);
    if ($storageFailure !== '') {
      $logger->expects($this->once())->method(str_starts_with($storageFailure, 'directory') ? 'error' : 'warning');
    }

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn([]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $token = $this->createMock(Token::class);
    $token->method('replace')->willReturnArgument(0);

    $screening = $this->createMock(MediaIntakeScreening::class);
    $screening->method('register')->willReturnCallback(function (MediaInterface $media): void {
      $this->queuedMedia[] = $media->id();
    });
    $screening->expects($this->never())->method('enqueue');
    return new TestablePromoterForAttachments(
      $etm,
      $moduleHandler,
      $this->createMock(EventDispatcherInterface::class),
      $logger,
      $entityFieldManager,
      $configFactory,
      $fileSystem,
      $token,
      $this->createMock(InternalRemarkWriter::class),
      $this->createMock(LockBackendInterface::class),
      NULL,
      NULL,
      $this->createMock(LanguageManagerInterface::class),
      NULL,
      NULL,
      $screening,
    );
  }

  /**
   * Builds a file mock with an id and uri.
   */
  protected function file(int $fid, string $scheme = 'private'): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('id')->willReturn($fid);
    $file->method('getFileUri')->willReturn($scheme . '://mail-inbound-staged/mail-' . $fid . '.jpg');
    return $file;
  }

  /**
   * Builds a mail mock exposing the given attachment file ids.
   */
  protected function mail(array $fids): InboundMail {
    $mail = $this->createMock(InboundMail::class);
    $mail->method('getAttachmentFileIds')->willReturn($fids);
    return $mail;
  }

  /**
   * Builds a node mock with/without field_request_media.
   */
  protected function node(bool $hasMediaField): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('hasField')->with('field_request_media')->willReturn($hasMediaField);
    return $node;
  }

  /**
   * One failing media save releases ONLY the sibling file's reference.
   *
   * @covers ::buildAttachmentMedia
   */
  public function testPartialMediaFailureKeepsFailedFileReferenced(): void {
    $mediaCreations = 0;
    $promoter = $this->promoter(
      [7 => $this->file(7), 8 => $this->file(8)],
      throwOnFids: [7],
      mediaCreations: $mediaCreations,
    );

    $result = $promoter->exposeBuildAttachmentMedia($this->mail([7, 8]), $this->node(TRUE));

    // The sibling (fid 8) made it onto the node and is releasable; the
    // failed file (fid 7) is NOT in the released set, so its reference on
    // the mail keeps it reachable for discard / uninstall cleanup.
    $this->assertSame([8], $result['fids']);
    $this->assertCount(1, $result['items']);
    $this->assertSame(1008, $result['items'][0]['target_id']);
    $this->assertSame(2, $mediaCreations, 'Both files were attempted.');
    $this->assertSame([1008], $this->queuedMedia, 'Successful media are held before node insert hooks can publish them.');
  }

  /**
   * Attachment media stay unpublished until vision screening has run.
   *
   * @covers ::buildAttachmentMedia
   */
  public function testAttachmentMediaAreCreatedUnpublished(): void {
    $mediaCreations = 0;
    $promoter = $this->promoter(
      [7 => $this->file(7), 8 => $this->file(8)],
      throwOnFids: [],
      mediaCreations: $mediaCreations,
    );

    $promoter->exposeBuildAttachmentMedia($this->mail([7, 8]), $this->node(TRUE));

    $this->assertSame(['unpublished', 'unpublished'], $this->publicationCalls);
    $this->assertSame([1007, 1008], $this->queuedMedia);
  }

  /**
   * A missing file id is skipped without aborting the siblings.
   *
   * @covers ::buildAttachmentMedia
   */
  public function testMissingFileIsSkipped(): void {
    $mediaCreations = 0;
    $promoter = $this->promoter(
      [9 => $this->file(9)],
      throwOnFids: [],
      mediaCreations: $mediaCreations,
    );

    $result = $promoter->exposeBuildAttachmentMedia($this->mail([5, 9]), $this->node(TRUE));

    $this->assertSame([9], $result['fids']);
    $this->assertSame(1, $mediaCreations, 'Only the existing file minted a media entity.');
  }

  /**
   * Without field_request_media NO media entity is created (LOW carry-over).
   *
   * @covers ::buildAttachmentMedia
   */
  public function testNoMediaCreatedWhenFieldMissing(): void {
    $mediaCreations = 0;
    $promoter = $this->promoter(
      [7 => $this->file(7)],
      throwOnFids: [],
      mediaCreations: $mediaCreations,
    );

    $result = $promoter->exposeBuildAttachmentMedia($this->mail([7]), $this->node(FALSE));

    $this->assertSame(['items' => [], 'fids' => []], $result);
    $this->assertSame(0, $mediaCreations, 'The field check runs BEFORE any media creation.');
  }

  /**
   * Public staging must never become a report attachment on storage failure.
   */
  #[DataProvider('storageFailures')]
  public function testStorageFailureKeepsAttachmentStaged(string $failure): void {
    $mediaCreations = 0;
    $file = $this->file(7, 'public');
    if ($failure === 'file save exception') {
      $uris = [];
      $file->expects($this->exactly(2))->method('setFileUri')->willReturnCallback(function ($uri) use (&$uris, $file) {
        $uris[] = $uri;
        return $file;
      });
      $file->expects($this->once())->method('save')->willThrowException(new EntityStorageException('Synthetic save failure.'));
    }
    else {
      $file->expects($this->never())->method('setFileUri');
      $file->expects($this->never())->method('save');
    }
    $promoter = $this->promoter([7 => $file], [], $mediaCreations, $failure);
    $result = $promoter->exposeBuildAttachmentMedia($this->mail([7]), $this->node(TRUE));
    $this->assertSame(['items' => [], 'fids' => []], $result);
    $this->assertSame(0, $mediaCreations);
    $this->assertSame([], $this->queuedMedia);
    if ($failure === 'file save exception') {
      $this->assertSame(['private://request-imports/mail-7.jpg', 'public://mail-inbound-staged/mail-7.jpg'], $uris);
    }
  }

  /**
   * Directory and move APIs can fail by return value or exception.
   */
  public static function storageFailures(): array {
    return array_map(static fn(string $failure): array => [$failure], [
      'directory false', 'directory exception', 'move false', 'move exception', 'file save exception',
    ]);
  }

}

/**
 * Test-only subclass exposing buildAttachmentMedia().
 */
class TestablePromoterForAttachments extends InboundMailPromoter {

  /**
   * Exposes buildAttachmentMedia().
   *
   * @return array{items: array<int, array<string, mixed>>, fids: int[]}
   *   The builder result.
   */
  public function exposeBuildAttachmentMedia(InboundMail $mail, NodeInterface $node): array {
    return $this->buildAttachmentMedia($mail, $node);
  }

}
