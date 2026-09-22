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
use TYPO3\CMS\Core\Core\Environment;

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
        self::assertFalse($subject->has(1, '/user_upload/unknown.jpg'));
    }

    #[Test]
    public function writtenPreviewIsReadBackUnchanged(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', 'webp-bytes');

        self::assertTrue($subject->has(1, '/user_upload/hero.jpg'));
        self::assertSame('webp-bytes', $subject->read(1, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function theSameIdentifierInDifferentStoragesDoesNotCollide(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', 'storage-one');
        $subject->write(2, '/user_upload/hero.jpg', 'storage-two');

        self::assertSame('storage-one', $subject->read(1, '/user_upload/hero.jpg'));
        self::assertSame('storage-two', $subject->read(2, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function previewsAreSpreadOverSubdirectories(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', 'webp-bytes');

        self::assertCount(1, glob(Environment::getVarPath().'/file-sync/previews/*/*.webp') ?: []);
    }

    #[Test]
    public function removeDeletesThePreview(): void
    {
        $subject = new PreviewStore();
        $subject->write(1, '/user_upload/hero.jpg', 'webp-bytes');
        $subject->remove(1, '/user_upload/hero.jpg');

        self::assertFalse($subject->has(1, '/user_upload/hero.jpg'));
    }

    #[Test]
    public function removingAnUnknownPreviewIsNotAnError(): void
    {
        $subject = new PreviewStore();
        $subject->remove(1, '/user_upload/unknown.jpg');

        self::assertFalse($subject->has(1, '/user_upload/unknown.jpg'));
    }
}
