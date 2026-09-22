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
use Symfony\Component\RateLimiter\Storage\StorageInterface;
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
 * Two limiters, not one: per client address, and one for the site as a
 * whole behind it. The first is the useful bound in ordinary traffic; the
 * second is what still holds when the address itself is attacker-supplied,
 * which it is under a proxy configured to trust X-Forwarded-For.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class MaterializeRateLimiter
{
    /**
     * A page load costs two requests at most, one per stage, so this is
     * generous for a visitor and still cheap to exceed on purpose.
     */
    private const LIMIT = 60;

    /**
     * The whole site's budget, because the key above is not always the
     * site's to choose. NormalizedParams::determineRemoteAddress() takes the
     * first X-Forwarded-For entry when SYS/reverseProxyHeaderMultiValue is
     * 'first' and SYS/reverseProxyIP matches the peer, and that entry is
     * whatever the caller wrote. A limiter keyed on a value the caller
     * supplies bounds nothing, so a second one keyed on nothing at all has
     * to sit behind it.
     *
     * Ten times the per-address figure. A page view costs at most two
     * requests, and only for images that are still deferred, so 600 a minute
     * is three hundred such page views a minute, well past what the staging
     * and development instances this extension exists for ever see, while
     * capping the outbound burst against the remote instance at 600 batches
     * however many addresses one caller invents.
     */
    private const GLOBAL_LIMIT = 600;

    private const INTERVAL = '1 minute';

    public function __construct(private CacheManager $cacheManager) {}

    public function isAccepted(ServerRequestInterface $request): bool
    {
        // One storage for both, because CachingFrameworkStorage collects
        // garbage in its constructor and once per request is enough.
        $storage = GeneralUtility::makeInstance(CachingFrameworkStorage::class, $this->cacheManager);

        // Per address first, and the site's budget spent only once that one
        // has admitted the request: a caller flooding from a single address
        // has to exhaust its own window rather than everybody else's.
        return $this->limiter($storage, 'typo3-file-sync-materialize', self::LIMIT, $this->remoteAddress($request))
            ->consume()->isAccepted()
            && $this->limiter($storage, 'typo3-file-sync-materialize-site', self::GLOBAL_LIMIT, 'site')
                ->consume()->isAccepted();
    }

    /**
     * Built here rather than injected: the storage above is the expensive
     * part, and every frontend request would pay for it even though almost
     * none of them reach this endpoint.
     */
    private function limiter(StorageInterface $storage, string $id, int $limit, string $key): LimiterInterface
    {
        $factory = new RateLimiterFactory(
            [
                'id' => $id,
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => self::INTERVAL,
            ],
            $storage,
        );

        return $factory->create($key);
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
