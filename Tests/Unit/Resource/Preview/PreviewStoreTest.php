<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_file_sync" TYPO3 CMS extension.
 *
 * (c) 2025-2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource\Preview;

use KonradMichalik\Ttt\Attribute\{WithEnvironment, WithTypo3ConfVars};
use KonradMichalik\Typo3FileSync\Resource\Preview\PreviewStore;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;

use function dirname;
use function pack;
use function restore_error_handler;
use function set_error_handler;
use function strlen;
use function substr;

/**
 * PreviewStoreTest.
 *
 * GeneralUtility::mkdir_deep() fixes permissions on every directory it
 * creates, which needs both an initialized Environment and a configured
 * folderCreateMask, neither of which a plain unit test provides on its own.
 * #[WithEnvironment] gives each test method its own fresh, temporary var
 * path (applied after setUp(), so the subject under test is constructed
 * inside each test method rather than in setUp()), and is torn down
 * automatically once the test finishes, so no manual cleanup is needed here.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PreviewStore::class)]
#[WithEnvironment]
#[WithTypo3ConfVars(['SYS' => ['folderCreateMask' => '2775']])]
final class PreviewStoreTest extends TestCase
{
    #[Test]
    public function readReturnsNullForAnUnknownFile(): void
    {
        $subject = new PreviewStore();

        self::assertNull($subject->read(1, '/user_upload/unknown.jpg'));
    }

    #[Test]
    public function writtenPreviewIsReadBackUnchanged(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', self::webp('webp-bytes'));

        self::assertSame(self::webp('webp-bytes'), $subject->read(1, '/user_upload/hero.jpg'));
    }

    /**
     * GeneralUtility::writeFile() is not atomic, so a full disk leaves a
     * file that is there and non-empty behind. Nothing ever rewrites a key
     * the store already holds, which is what turns one short write into a
     * preview served for the lifetime of the store.
     */
    #[Test]
    public function aPreviewWrittenShortReadsAsAbsent(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', self::webp('a-payload-of-some-length'));
        self::truncateStoredFileBy(4);

        // The file is still there, which is what a short write leaves
        // behind: unreadable as a preview rather than absent.
        self::assertCount(1, self::storedFiles());
        self::assertNull($subject->read(1, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function contentThatIsNotAWebPAtAllReadsAsAbsent(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', 'not-an-image');

        self::assertNull($subject->read(1, '/user_upload/hero.jpg'));
    }

    /**
     * PreviewService parks its failure markers in this store, and a marker
     * holds the second it was written in rather than a picture. The WebP
     * check must not swallow those, or a failing rendition would be retried
     * by every visitor.
     */
    #[Test]
    public function aMarkerIsReadBackAlthoughItIsNotAPicture(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, 'failed:/user_upload/hero.jpg', '1700000000');

        self::assertSame('1700000000', $subject->readMarker(1, 'failed:/user_upload/hero.jpg'));
    }

    #[Test]
    public function theSameIdentifierInDifferentStoragesDoesNotCollide(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', self::webp('storage-one'));
        $subject->write(2, '/user_upload/hero.jpg', self::webp('storage-two'));

        self::assertSame(self::webp('storage-one'), $subject->read(1, '/user_upload/hero.jpg'));
        self::assertSame(self::webp('storage-two'), $subject->read(2, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function previewsAreSpreadOverSubdirectories(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', self::webp('webp-bytes'));

        self::assertCount(1, self::storedFiles());
    }

    #[Test]
    public function removeDeletesThePreview(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', self::webp('webp-bytes'));
        $subject->remove(1, '/user_upload/hero.jpg');

        self::assertSame([], self::storedFiles());
    }

    #[Test]
    public function removingAnUnknownPreviewIsNotAnError(): void
    {
        $subject = new PreviewStore();
        $subject->remove(1, '/user_upload/unknown.jpg');

        self::assertNull($subject->read(1, '/user_upload/unknown.jpg'));
    }

    /**
     * A store whose directory exists but cannot be written to is the
     * ordinary shape of a var/ owned by another user. Nothing throws on its
     * own there, so the warning PreviewService promises is never logged and
     * every visitor keeps paying the fetch the store exists to avoid.
     *
     * Modelled by putting a directory where the file has to go, rather than
     * by taking write permission away, because a test suite running as root
     * would write straight through a permission bit.
     */
    #[Test]
    public function aWriteThatCannotHappenThrows(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', self::webp('webp-bytes'));
        self::replaceStoredFileWithADirectory();

        $this->expectException(RuntimeException::class);

        // GeneralUtility::writeFile() calls fopen() unsuppressed, so the very
        // failure under test also raises a PHP warning from core. Silenced
        // rather than asserted, because it is not this store's to emit.
        set_error_handler(static fn (): bool => true);

        try {
            $subject->write(1, '/user_upload/hero.jpg', self::webp('webp-bytes'));
        } finally {
            restore_error_handler();
        }
    }

    /**
     * A marker that cannot be deleted leaves a rendition that works again
     * damped for the rest of the window, and the caller answers 'unavailable'
     * for a picture it could have produced. Silence there is invisible, so
     * the store reports it the same way a failed write is reported.
     *
     * Modelled by taking write permission off the directory, which is the one
     * way a regular file survives an unlink(). That is exactly why the test
     * has to give up when the mode does not apply to the running user.
     */
    #[Test]
    public function aRemovalThatCannotHappenThrows(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', self::webp('webp-bytes'));
        $directory = dirname(self::storedFiles()[0]);
        chmod($directory, 0o555);

        try {
            if (is_writable($directory)) {
                self::markTestSkipped('The store directory stayed writable, so a failing unlink() cannot be modelled here.');
            }

            $this->expectException(RuntimeException::class);

            // unlink() raises a PHP warning of its own on the very failure
            // under test. Silenced rather than asserted, because it is not
            // this store's to emit.
            set_error_handler(static fn (): bool => true);

            try {
                $subject->remove(1, '/user_upload/hero.jpg');
            } finally {
                restore_error_handler();
            }
        } finally {
            chmod($directory, 0o775);
        }
    }

    /**
     * A RIFF container with the length field a real encoder would write,
     * which is the only thing the store inspects. Built rather than encoded
     * with GD, so the payload stays readable in a failure message.
     */
    private static function webp(string $payload): string
    {
        return 'RIFF'.pack('V', 4 + strlen($payload)).'WEBP'.$payload;
    }

    /**
     * @return list<string>
     */
    private static function storedFiles(): array
    {
        return glob(Environment::getVarPath().'/file-sync/previews/*/*.webp') ?: [];
    }

    private static function replaceStoredFileWithADirectory(): void
    {
        $path = self::storedFiles()[0];
        unlink($path);
        mkdir($path);
    }

    /**
     * Shortens the one file the store wrote, the way a write that ran out of
     * disk would leave it.
     */
    private static function truncateStoredFileBy(int $bytes): void
    {
        $path = self::storedFiles()[0];
        $contents = (string) file_get_contents($path);
        file_put_contents($path, substr($contents, 0, strlen($contents) - $bytes));
    }
}
