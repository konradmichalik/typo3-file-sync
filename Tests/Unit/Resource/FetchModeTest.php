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

use KonradMichalik\Typo3FileSync\Resource\{FetchMode, GeneratingResourceInterface, RemoteResourceInterface};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\MockObject\MockObject;
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

    #[Test]
    public function everyHandlerIsAllowedByDefault(): void
    {
        $subject = new FetchMode();

        self::assertTrue($subject->allows('remote_instance', $this->createMock(RemoteResourceInterface::class)));
        self::assertTrue($subject->allows('placeholder_image', $this->generatingHandler()));
    }

    #[Test]
    public function restrictToAllowsOnlyTheNamedHandlers(): void
    {
        $subject = new FetchMode();
        $subject->restrictTo(['remote_instance']);

        self::assertTrue($subject->allows('remote_instance', $this->createMock(RemoteResourceInterface::class)));
        self::assertFalse($subject->allows('custom', $this->createMock(RemoteResourceInterface::class)));
    }

    #[Test]
    public function restrictToAllowsAGeneratingHandlerThatIsNamedExplicitly(): void
    {
        $subject = new FetchMode();
        $subject->restrictTo(['placeholder_image']);

        self::assertTrue($subject->allows('placeholder_image', $this->generatingHandler()));
    }

    #[Test]
    public function skipGeneratingRejectsOnlyGeneratingHandlers(): void
    {
        $subject = new FetchMode();
        $subject->skipGenerating();

        self::assertTrue($subject->allows('remote_instance', $this->createMock(RemoteResourceInterface::class)));
        self::assertFalse($subject->allows('placeholder_image', $this->generatingHandler()));
    }

    #[Test]
    public function clearHandlerRestrictionAllowsEveryHandlerAgain(): void
    {
        $subject = new FetchMode();
        $subject->restrictTo(['remote_instance']);
        $subject->skipGenerating();
        $subject->clearHandlerRestriction();

        self::assertTrue($subject->allows('custom', $this->createMock(RemoteResourceInterface::class)));
        self::assertTrue($subject->allows('placeholder_image', $this->generatingHandler()));
    }

    private function generatingHandler(): RemoteResourceInterface
    {
        /** @var GeneratingResourceInterface&MockObject&RemoteResourceInterface $handler */
        $handler = $this->createMockForIntersectionOfInterfaces([RemoteResourceInterface::class, GeneratingResourceInterface::class]);

        return $handler;
    }

    private function givenFrontendRequest(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
    }
}
