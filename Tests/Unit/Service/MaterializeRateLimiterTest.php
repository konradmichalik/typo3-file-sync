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

use KonradMichalik\Ttt\Attribute\{WithEnvironment, WithTypo3ConfVars};
use KonradMichalik\Typo3FileSync\Service\MaterializeRateLimiter;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};

/**
 * MaterializeRateLimiterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(MaterializeRateLimiter::class)]
#[WithEnvironment]
#[WithTypo3ConfVars(['SYS' => ['reverseProxyIP' => '', 'reverseProxyHeaderMultiValue' => 'none']])]
final class MaterializeRateLimiterTest extends TestCase
{
    /** @var array<string, string> */
    private array $store = [];

    private MaterializeRateLimiter $subject;

    protected function setUp(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $id): string|false => $this->store[$id] ?? false);
        $cache->method('set')->willReturnCallback(function (string $id, string $data): void {
            $this->store[$id] = $data;
        });

        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('ratelimiter')->willReturn($cache);

        $this->subject = new MaterializeRateLimiter($cacheManager);
    }

    #[Test]
    public function isAddressAcceptedAcceptsAFreshAddress(): void
    {
        $request = (new ServerRequest('https://example.com/'))
            ->withAttribute('normalizedParams', $this->normalizedParamsFor('10.0.0.1'));

        self::assertTrue($this->subject->isAddressAccepted($request));
    }

    #[Test]
    public function isAddressAcceptedRejectsOnceTheLimitIsSpent(): void
    {
        $request = (new ServerRequest('https://example.com/'))
            ->withAttribute('normalizedParams', $this->normalizedParamsFor('10.0.0.2'));

        for ($i = 0; $i < 60; ++$i) {
            self::assertTrue($this->subject->isAddressAccepted($request));
        }

        self::assertFalse($this->subject->isAddressAccepted($request));
    }

    #[Test]
    public function isAddressAcceptedFallsBackToDerivingTheAddressWhenNoNormalizedParamsAreAttached(): void
    {
        $request = new ServerRequest('https://example.com/', null, 'php://input', [], ['REMOTE_ADDR' => '10.0.0.3']);

        self::assertTrue($this->subject->isAddressAccepted($request));
    }

    #[Test]
    public function isSiteAcceptedAcceptsWithinItsBudget(): void
    {
        self::assertTrue($this->subject->isSiteAccepted());
    }

    #[Test]
    public function isSiteAcceptedRejectsOnceTheGlobalLimitIsSpent(): void
    {
        for ($i = 0; $i < 600; ++$i) {
            self::assertTrue($this->subject->isSiteAccepted());
        }

        self::assertFalse($this->subject->isSiteAccepted());
    }

    #[Test]
    public function isAddressAcceptedAndIsSiteAcceptedConsumeIndependentBudgets(): void
    {
        $request = (new ServerRequest('https://example.com/'))
            ->withAttribute('normalizedParams', $this->normalizedParamsFor('10.0.0.4'));

        for ($i = 0; $i < 60; ++$i) {
            self::assertTrue($this->subject->isAddressAccepted($request));
        }

        self::assertFalse($this->subject->isAddressAccepted($request));
        self::assertTrue($this->subject->isSiteAccepted());
    }

    private function normalizedParamsFor(string $remoteAddress): NormalizedParams
    {
        return NormalizedParams::createFromRequest(new ServerRequest('https://example.com/', null, 'php://input', [], ['REMOTE_ADDR' => $remoteAddress]));
    }
}
