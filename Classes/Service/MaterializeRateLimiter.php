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

namespace KonradMichalik\Typo3FileSync\Service;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\RateLimiter\{LimiterInterface, RateLimiterFactory};
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MaterializeRateLimiter.
 *
 * MaterializationService damps repeat work on the same original for five
 * minutes, but nothing bounds how many distinct originals a caller asks for,
 * or how many callers ask at once. The tokens are readable in any page's
 * HTML and are not session bound, so without this a handful of parallel
 * callers can turn a public endpoint into a burst of outbound fetches
 * against the remote instance.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class MaterializeRateLimiter
{
    /**
     * A page load costs one request, so this is generous for a visitor and
     * still cheap to exceed on purpose.
     */
    private const LIMIT = 60;

    private const INTERVAL = '1 minute';

    public function __construct(private CacheManager $cacheManager) {}

    public function isAccepted(ServerRequestInterface $request): bool
    {
        return $this->limiterFor($this->remoteAddress($request))->consume()->isAccepted();
    }

    /**
     * Built here rather than injected: CachingFrameworkStorage collects
     * garbage in its constructor, which every frontend request would pay for
     * even though almost none of them reach this endpoint.
     */
    private function limiterFor(string $remoteAddress): LimiterInterface
    {
        $factory = new RateLimiterFactory(
            [
                'id' => 'typo3-file-sync-materialize',
                'policy' => 'sliding_window',
                'limit' => self::LIMIT,
                'interval' => self::INTERVAL,
            ],
            GeneralUtility::makeInstance(CachingFrameworkStorage::class, $this->cacheManager),
        );

        return $factory->create($remoteAddress);
    }

    private function remoteAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }

        return $normalizedParams->getRemoteAddress();
    }
}
