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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PreviewStoreTest.
 *
 * GeneralUtility::mkdir_deep() fixes permissions on every directory it
 * creates, which needs both an initialized Environment and a configured
 * folderCreateMask, neither of which a plain unit test provides on its own.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PreviewStore::class)]
#[WithEnvironment]
#[WithTypo3ConfVars(['SYS' => ['folderCreateMask' => '2775']])]
final class PreviewStoreTest extends TestCase
{
    private string $basePath;
    private PreviewStore $subject;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/file-sync-preview-'.bin2hex(random_bytes(8));
        $this->subject = new PreviewStore($this->basePath);
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->basePath, true);
    }

    #[Test]
    public function readReturnsNullForAnUnknownFile(): void
    {
        self::assertNull($this->subject->read(1, '/user_upload/unknown.jpg'));
        self::assertFalse($this->subject->has(1, '/user_upload/unknown.jpg'));
    }

    #[Test]
    public function writtenPreviewIsReadBackUnchanged(): void
    {
        $this->subject->write(1, '/user_upload/hero.jpg', 'webp-bytes');

        self::assertTrue($this->subject->has(1, '/user_upload/hero.jpg'));
        self::assertSame('webp-bytes', $this->subject->read(1, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function theSameIdentifierInDifferentStoragesDoesNotCollide(): void
    {
        $this->subject->write(1, '/user_upload/hero.jpg', 'storage-one');
        $this->subject->write(2, '/user_upload/hero.jpg', 'storage-two');

        self::assertSame('storage-one', $this->subject->read(1, '/user_upload/hero.jpg'));
        self::assertSame('storage-two', $this->subject->read(2, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function previewsAreSpreadOverSubdirectories(): void
    {
        $this->subject->write(1, '/user_upload/hero.jpg', 'webp-bytes');

        self::assertCount(1, glob($this->basePath.'/*/*.webp') ?: []);
    }

    #[Test]
    public function removeDeletesThePreview(): void
    {
        $this->subject->write(1, '/user_upload/hero.jpg', 'webp-bytes');
        $this->subject->remove(1, '/user_upload/hero.jpg');

        self::assertFalse($this->subject->has(1, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function removingAnUnknownPreviewIsNotAnError(): void
    {
        $this->subject->remove(1, '/user_upload/unknown.jpg');

        self::assertFalse($this->subject->has(1, '/user_upload/unknown.jpg'));
    }
}
