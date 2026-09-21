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

namespace KonradMichalik\Typo3FileSync\Middleware;

use InvalidArgumentException;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, StorageService};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\Resource\{ResourceStorage, StorageRepository};
use TYPO3\CMS\Core\Utility\PathUtility;

use function array_map;
use function array_unique;
use function array_values;
use function htmlspecialchars;
use function intval;
use function is_array;
use function is_string;
use function ltrim;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function rawurldecode;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strripos;
use function strtolower;
use function substr;
use function trim;
use function usort;

/**
 * DeferredImageMiddleware.
 *
 * The outermost frontend middleware, so it is the last to see the response
 * and therefore the only place that also sees a body served straight from
 * the page cache. It marks every image that still points at a provisional
 * rendition and injects the module that asks the materialize endpoint to
 * replace them.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DeferredImageMiddleware implements MiddlewareInterface
{
    private const ATTRIBUTE = 'data-file-sync';

    private const CACHE_KEY = 'fileSyncProvisionalCount';

    /**
     * Short enough that an installation which just gained its first
     * provisional file starts marking without waiting for a cache flush,
     * long enough that the count is not recomputed per request.
     */
    private const CACHE_LIFETIME = 60;

    private const IMAGE_PATTERN = '/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>/i';

    public function __construct(
        private CacheManager $cacheManager,
        private DeferredTokenService $deferredTokenService,
        private Features $features,
        private FileRepository $fileRepository,
        private StorageRepository $storageRepository,
        private StorageService $storageService,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if (!$this->features->isFeatureEnabled(Configuration::FEATURE_DEFERRED_LOADING)) {
            return $response;
        }

        // Everything below reads the body or the database, so the two free
        // checks come first: this middleware runs on every frontend
        // response of every site that has the extension installed.
        if (200 !== $response->getStatusCode()
            || !str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html')
        ) {
            return $response;
        }

        $state = $this->provisionalState();
        if (0 === $state['count']) {
            return $response;
        }

        $body = $this->markBody((string) $response->getBody(), $state['storages']);
        if (null === $body) {
            return $response;
        }

        $response = $response->withBody($this->streamFactory->createStream($body));

        // ContentLengthResponseHeader sits inside this middleware and measured
        // the body before the snippet was added. Everything past the stale
        // length would be cut off.
        if (!$response->hasHeader('Content-Length')) {
            return $response;
        }

        return $response->withHeader('Content-Length', (string) $response->getBody()->getSize());
    }

    /**
     * @param list<int> $storageUids
     *
     * @return string|null the rewritten body, or null when nothing was marked
     */
    private function markBody(string $body, array $storageUids): ?string
    {
        if (1 > preg_match_all(self::IMAGE_PATTERN, $body, $matches)) {
            return null;
        }

        $identifierByUrl = $this->identifiersByUrl(array_values(array_unique($matches[2])), $storageUids);
        if ([] === $identifierByUrl) {
            return null;
        }

        $uidByIdentifier = $this->fileRepository->findProvisionalProcessedFiles(
            $storageUids,
            array_values(array_unique(array_values($identifierByUrl))),
        );
        if ([] === $uidByIdentifier) {
            return null;
        }

        $marked = 0;
        $result = preg_replace_callback(
            self::IMAGE_PATTERN,
            function (array $match) use ($identifierByUrl, $uidByIdentifier, &$marked): string {
                $identifier = $identifierByUrl[$match[2]] ?? null;
                $uid = null === $identifier ? null : ($uidByIdentifier[$identifier] ?? null);
                $tag = $this->withAttribute($match[0], $uid);
                if ($tag !== $match[0]) {
                    ++$marked;
                }

                return $tag;
            },
            $body,
        );

        if (!is_string($result) || 0 === $marked) {
            return null;
        }

        return $this->injectSnippet($result);
    }

    /**
     * Rewrites markup this extension does not own, so it declines every tag
     * it is not certain about: one that is already marked, and one whose
     * quotes do not balance, which means the pattern stopped at a ">" inside
     * an attribute value and the match is only part of the real tag.
     */
    private function withAttribute(string $tag, ?int $processedFileUid): string
    {
        if (null === $processedFileUid || str_contains(strtolower($tag), self::ATTRIBUTE)) {
            return $tag;
        }

        if (1 === preg_match('/["\']/', (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $tag))) {
            return $tag;
        }

        $token = $this->deferredTokenService->create($processedFileUid);
        $head = rtrim(substr($tag, 0, -1));
        if (str_ends_with($head, '/')) {
            return rtrim(substr($head, 0, -1)).' '.self::ATTRIBUTE.'="'.$token.'" />';
        }

        return $head.' '.self::ATTRIBUTE.'="'.$token.'">';
    }

    /**
     * @param list<string> $urls
     * @param list<int>    $storageUids
     *
     * @return array<string, string>
     */
    private function identifiersByUrl(array $urls, array $storageUids): array
    {
        $prefixes = $this->publicPrefixes($storageUids);
        if ([] === $prefixes) {
            return [];
        }

        $map = [];
        foreach ($urls as $url) {
            $identifier = self::toIdentifier($url, $prefixes);
            if (null !== $identifier) {
                $map[$url] = $identifier;
            }
        }

        return $map;
    }

    /**
     * A src is whatever the renderer produced: site-relative with or without
     * a leading slash depending on absRefPrefix, or absolute when the site
     * points its assets at another host. Anchoring on the storage prefix as
     * a path segment covers all three, and a wrong guess costs nothing
     * because the lookup is an exact match on the processed file identifier.
     *
     * @param list<string> $prefixes
     */
    private static function toIdentifier(string $url, array $prefixes): ?string
    {
        $path = parse_url($url, \PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return null;
        }

        $path = '/'.ltrim(rawurldecode($path), '/');
        foreach ($prefixes as $prefix) {
            $position = strpos($path, $prefix);
            if (false !== $position) {
                return '/'.substr($path, $position + strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @param list<int> $storageUids
     *
     * @return list<string>
     */
    private function publicPrefixes(array $storageUids): array
    {
        $prefixes = [];
        foreach ($storageUids as $storageUid) {
            if ($storageUid < 1) {
                continue;
            }

            try {
                $storage = $this->storageRepository->getStorageObject($storageUid);
            } catch (InvalidArgumentException) {
                continue;
            }

            $prefix = self::publicPrefix($storage);
            if (null !== $prefix) {
                $prefixes[] = $prefix;
            }
        }

        $prefixes = array_values(array_unique($prefixes));
        // A nested storage must win over the one it sits inside, otherwise
        // its files are resolved against the wrong root.
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    private static function publicPrefix(ResourceStorage $storage): ?string
    {
        // getRootLevelFolder(false) bypasses backend file mounts, which are
        // irrelevant to a frontend URL and would yield a subfolder.
        $publicUrl = $storage->getPublicUrl($storage->getRootLevelFolder(false));
        if (null === $publicUrl) {
            return null;
        }

        $path = parse_url($publicUrl, \PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $path = trim(rawurldecode($path), '/');

        return '' === $path ? '/' : '/'.$path.'/';
    }

    private function injectSnippet(string $body): string
    {
        $position = strripos($body, '</body>');
        if (false === $position) {
            return $body.$this->snippet();
        }

        return substr($body, 0, $position).$this->snippet().substr($body, $position);
    }

    private function snippet(): string
    {
        return '<style>@media (prefers-reduced-motion: no-preference){'
            .'::view-transition-old(root),::view-transition-new(root){animation-duration:250ms}}</style>'
            .'<script type="module" src="'.htmlspecialchars($this->assetUrl(), \ENT_QUOTES).'"></script>';
    }

    private function assetUrl(): string
    {
        return PathUtility::getPublicResourceWebPath('EXT:'.Configuration::EXT_KEY.'/Resources/Public/JavaScript/file-sync.js');
    }

    /**
     * The one lookup a settled installation pays for. Nothing above it
     * touches the body and nothing below it runs while the count is zero.
     *
     * @return array{storages: list<int>, count: int}
     */
    private function provisionalState(): array
    {
        $runtimeCache = $this->cacheManager->getCache('runtime');
        $state = self::readState($runtimeCache);
        if (null !== $state) {
            return $state;
        }

        $persistentCache = $this->cacheManager->getCache('hash');
        $state = self::readState($persistentCache);
        if (null === $state) {
            $storages = $this->storageService->getDeferredStorageUids();
            $state = ['storages' => $storages, 'count' => $this->fileRepository->countProvisional($storages)];
            $persistentCache->set(self::CACHE_KEY, $state, [], self::CACHE_LIFETIME);
        }

        $runtimeCache->set(self::CACHE_KEY, $state);

        return $state;
    }

    /**
     * @return array{storages: list<int>, count: int}|null
     */
    private static function readState(FrontendInterface $cache): ?array
    {
        $state = $cache->get(self::CACHE_KEY);
        if (!is_array($state) || !isset($state['count']) || !is_array($state['storages'] ?? null)) {
            return null;
        }

        return [
            'storages' => array_map(intval(...), array_values($state['storages'])),
            'count' => (int) $state['count'],
        ];
    }
}
