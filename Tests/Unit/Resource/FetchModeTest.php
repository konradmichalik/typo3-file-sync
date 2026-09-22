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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource;

use KonradMichalik\Typo3FileSync\Resource\FetchMode;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * FetchModeTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(FetchMode::class)]
final class FetchModeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
    }

    #[Test]
    public function registeredStorageIsDeferredInAFrontendRequest(): void
    {
        $this->givenFrontendRequest();
        $subject = new FetchMode();
        $subject->registerStorage(1, true);

        self::assertTrue($subject->isDeferred(1));
    }

    #[Test]
    public function unregisteredStorageIsNeverDeferred(): void
    {
        $this->givenFrontendRequest();
        $subject = new FetchMode();

        self::assertFalse($subject->isDeferred(7));
    }

    #[Test]
    public function storageRegisteredAsNotDeferredStaysSynchronous(): void
    {
        $this->givenFrontendRequest();
        $subject = new FetchMode();
        $subject->registerStorage(1, false);

        self::assertFalse($subject->isDeferred(1));
    }

    #[Test]
    public function nothingIsDeferredOutsideAFrontendRequest(): void
    {
        $subject = new FetchMode();
        $subject->registerStorage(1, true);

        self::assertFalse($subject->isDeferred(1));
    }

    #[Test]
    public function forceSynchronousOverridesAFrontendRequest(): void
    {
        $this->givenFrontendRequest();
        $subject = new FetchMode();
        $subject->registerStorage(1, true);
        $subject->forceSynchronous();

        self::assertFalse($subject->isDeferred(1));
    }

    private function givenFrontendRequest(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
    }
}
