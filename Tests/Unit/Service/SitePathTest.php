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

use KonradMichalik\Typo3FileSync\Service\SitePath;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * SitePathTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(SitePath::class)]
final class SitePathTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI']);
        GeneralUtility::flushInternalRuntimeCaches();
    }

    #[Test]
    public function prefixIsASlashForASiteAtTheDocumentRoot(): void
    {
        self::useSitePath('/');

        self::assertSame('/', SitePath::prefix());
    }

    #[Test]
    public function prefixIsRootedAndTrailingSlashedForASiteBelowTheDocumentRoot(): void
    {
        self::useSitePath('/sub/dir/');

        self::assertSame('/sub/dir/', SitePath::prefix());
    }

    #[Test]
    public function absoluteLeavesAUrlWithASchemeUntouched(): void
    {
        self::useSitePath('/sub/dir/');

        self::assertSame('https://cdn.example.com/x.jpg', SitePath::absolute('https://cdn.example.com/x.jpg'));
    }

    #[Test]
    public function absoluteLeavesAnAlreadyRootedUrlUntouched(): void
    {
        self::useSitePath('/sub/dir/');

        self::assertSame('/fileadmin/x.jpg', SitePath::absolute('/fileadmin/x.jpg'));
    }

    #[Test]
    public function absoluteRootsARelativeUrlAtTheSite(): void
    {
        self::useSitePath('/sub/dir/');

        self::assertSame('/sub/dir/fileadmin/x.jpg', SitePath::absolute('fileadmin/x.jpg'));
    }

    private static function useSitePath(string $sitePath): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = $sitePath.'index.php';
        $_SERVER['REQUEST_URI'] = $sitePath;
        GeneralUtility::flushInternalRuntimeCaches();
    }
}
