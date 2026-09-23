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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Service;

use KonradMichalik\Typo3FileSync\Service\StashedFile;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * StashedFileTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(StashedFile::class)]
final class StashedFileTest extends TestCase
{
    private string $dir;

    private string $path;

    private string $stashPath;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/file-sync-stash-test-'.uniqid();
        mkdir($this->dir);
        $this->path = $this->dir.'/original.jpg';
        $this->stashPath = $this->dir.'/.tx-file-sync-stash-original.jpg';
        file_put_contents($this->path, 'original content');
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->stashPath] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    #[Test]
    public function stashMovesTheFileOutOfTheWay(): void
    {
        $subject = StashedFile::stash($this->path);

        self::assertNotNull($subject);
        self::assertFileDoesNotExist($this->path);
        self::assertFileExists($this->stashPath);
        self::assertSame('original content', file_get_contents($this->stashPath));
    }

    #[Test]
    public function stashReturnsNullWhenTheFileCannotBeMoved(): void
    {
        // rename() raises a PHP warning of its own on the very failure under
        // test. Silenced rather than asserted, because it is not this
        // class's to emit.
        set_error_handler(static fn (): bool => true);

        try {
            self::assertNull(StashedFile::stash($this->dir.'/does-not-exist.jpg'));
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function restorePutsTheStashedFileBack(): void
    {
        $subject = StashedFile::stash($this->path);
        self::assertNotNull($subject);
        file_put_contents($this->path, 'replacement content');

        $subject->restore();

        self::assertSame('original content', file_get_contents($this->path));
        self::assertFileDoesNotExist($this->stashPath);
    }

    #[Test]
    public function restoreDoesNothingWhenTheStashIsAlreadyGone(): void
    {
        $subject = StashedFile::stash($this->path);
        self::assertNotNull($subject);
        unlink($this->stashPath);

        $subject->restore();

        self::assertFileDoesNotExist($this->path);
    }

    #[Test]
    public function discardRemovesTheStashedFile(): void
    {
        $subject = StashedFile::stash($this->path);
        self::assertNotNull($subject);

        $subject->discard();

        self::assertFileDoesNotExist($this->stashPath);
    }

    #[Test]
    public function discardDoesNothingWhenTheStashIsAlreadyGone(): void
    {
        $subject = StashedFile::stash($this->path);
        self::assertNotNull($subject);
        unlink($this->stashPath);

        $subject->discard();

        self::assertFileDoesNotExist($this->stashPath);
    }
}
