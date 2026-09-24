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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Service;

use KonradMichalik\Typo3FileSync\Service\SitePath;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SitePathTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(SitePath::class)]
final class SitePathTest extends FunctionalTestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        GeneralUtility::flushInternalRuntimeCaches();
        parent::tearDown();
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

    /**
     * TYPO3 derives the site path from the entry script and the request,
     * both of which are meaningless under PHPUnit. Pointing them at an
     * index.php below $sitePath is what a real installation at that path
     * looks like.
     */
    private static function useSitePath(string $sitePath): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = $sitePath.'index.php';
        $_SERVER['REQUEST_URI'] = $sitePath;
        GeneralUtility::flushInternalRuntimeCaches();
    }
}
