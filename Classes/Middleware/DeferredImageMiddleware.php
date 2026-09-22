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
use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, SitePath, StorageService};
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

    private const ENDPOINT_ATTRIBUTE = 'data-file-sync-endpoint';

    private const CACHE_KEY = 'fileSyncProvisionalCount';

    /**
     * Short enough that an installation which just gained its first
     * provisional file starts marking without waiting for a cache flush,
     * long enough that the count is not recomputed per request.
     */
    private const CACHE_LIFETIME = 60;

    /**
     * "(?<![-\w])src=" rather than "\bsrc=": a word boundary also sits
     * between the hyphen and the "s" of data-src, and because [^>]* is
     * greedy the engine backtracks from the right and would settle on the
     * lazy-loading attribute instead of the src the browser renders.
     */
    private const IMAGE_PATTERN = '/<img\b[^>]*(?<![-\w])src=(["\'])([^"\']+)\1[^>]*>/i';

    /**
     * Spans whose contents are not markup the browser renders as elements.
     * An img inside them must be left alone: in a script an injected
     * attribute can terminate a JavaScript string literal, and in a
     * textarea it would show up as visible page text.
     */
    private const SKIP_PATTERN = '/<script\b[^>]*>.*?<\/script\s*>|<textarea\b[^>]*>.*?<\/textarea\s*>|<!--.*?-->/is';

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

        // Everything below reads the body or the database, so the free header
        // checks come first: this middleware runs on every frontend response
        // of every site that has the extension installed.
        if (!self::isRewritableHtml($response)) {
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

        $rewritten = $this->rewriteTags($body, $identifierByUrl, $uidByIdentifier);
        if (null === $rewritten) {
            return null;
        }

        return $this->injectSnippet($rewritten);
    }

    /**
     * Matched offsets are needed to tell a tag the browser renders from one
     * sitting inside a script, a textarea or a comment.
     *
     * @param array<string, string> $identifierByUrl
     * @param array<string, int>    $uidByIdentifier
     *
     * @return string|null the rewritten body, or null when nothing was marked
     */
    private function rewriteTags(string $body, array $identifierByUrl, array $uidByIdentifier): ?string
    {
        $skipSpans = self::skipSpans($body);
        $marked = 0;
        $total = 0;
        $result = preg_replace_callback(
            self::IMAGE_PATTERN,
            function (array $match) use ($identifierByUrl, $uidByIdentifier, $skipSpans, &$marked): string {
                [$tag, $offset] = $match[0];
                if (self::isWithinSpan($offset, $skipSpans)) {
                    return $tag;
                }

                $identifier = $identifierByUrl[$match[2][0]] ?? null;
                $uid = null === $identifier ? null : ($uidByIdentifier[$identifier] ?? null);
                $rewritten = $this->withAttribute($tag, $match[1][0], $uid);
                if ($rewritten !== $tag) {
                    ++$marked;
                }

                return $rewritten;
            },
            $body,
            -1,
            $total,
            \PREG_OFFSET_CAPTURE,
        );

        if (!is_string($result) || 0 === $marked) {
            return null;
        }

        return $result;
    }

    /**
     * Rewrites markup this extension does not own, so it declines every tag
     * it is not certain about: one that is already marked, and one whose
     * quotes do not balance, which means the pattern stopped at a ">" inside
     * an attribute value and the match is only part of the real tag.
     *
     * The new attribute reuses the quote character the tag already uses for
     * its src. A tag written with single quotes is the one that turns up
     * inside a double-quoted JavaScript string literal, where injecting a
     * double quote would end the string and break the whole script block.
     * The token is digits, a dot and hex, so it never needs escaping.
     */
    private function withAttribute(string $tag, string $quote, ?int $processedFileUid): string
    {
        if (null === $processedFileUid
            || str_contains(strtolower($tag), self::ATTRIBUTE)
            || self::hasUnbalancedQuotes($tag)
        ) {
            return $tag;
        }

        $attribute = ' '.self::ATTRIBUTE.'='.$quote.$this->deferredTokenService->create($processedFileUid).$quote;
        $head = rtrim(substr($tag, 0, -1));
        if (str_ends_with($head, '/')) {
            return rtrim(substr($head, 0, -1)).$attribute.' />';
        }

        return $head.$attribute.'>';
    }

    /**
     * The pattern stopped at a ">" inside an attribute value when the quotes
     * no longer balance, which means the match is only part of the real tag.
     */
    private static function hasUnbalancedQuotes(string $tag): bool
    {
        return 1 === preg_match('/["\']/', (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $tag));
    }

    /**
     * @return list<array{int, int}> start and end offset of each span
     */
    private static function skipSpans(string $body): array
    {
        preg_match_all(self::SKIP_PATTERN, $body, $matches, \PREG_OFFSET_CAPTURE);

        return array_map(
            static fn (array $match): array => [$match[1], $match[1] + strlen($match[0])],
            $matches[0],
        );
    }

    /**
     * @param list<array{int, int}> $spans
     */
    private static function isWithinSpan(int $offset, array $spans): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Content-Encoding means another middleware already compressed the
     * body, so what is in the stream is bytes rather than markup.
     */
    private static function isRewritableHtml(ResponseInterface $response): bool
    {
        return 200 === $response->getStatusCode()
            && '' === $response->getHeaderLine('Content-Encoding')
            && str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/html');
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
            $prefix = $this->publicPrefixOfStorage($storageUid);
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

    private function publicPrefixOfStorage(int $storageUid): ?string
    {
        // Storage 0 is the fallback storage and is never a deferred one.
        if ($storageUid < 1) {
            return null;
        }

        try {
            return self::publicPrefix($this->storageRepository->getStorageObject($storageUid));
        } catch (InvalidArgumentException) {
            return null;
        }
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

    /**
     * No inline style: the module consults prefers-reduced-motion itself
     * before it starts a view transition, and an inline style block would
     * be dropped by the CSP header that csp-headers has already emitted by
     * the time this middleware sees the response.
     *
     * The endpoint travels on the tag rather than being hardcoded in the
     * module, because only the server knows whether the site sits at the
     * document root or below a subdirectory. import.meta.url would not do:
     * the module is served from _assets/ or from typo3conf/ext/ depending on
     * the installation, so its own depth says nothing about the site root.
     */
    private function snippet(): string
    {
        return '<script type="module" src="'.htmlspecialchars($this->assetUrl(), \ENT_QUOTES).'"'
            .' '.self::ENDPOINT_ATTRIBUTE.'="'.htmlspecialchars(MaterializeMiddleware::endpointPath(), \ENT_QUOTES).'"'
            .'></script>';
    }

    /**
     * The site prefix comes from SitePath, not from PathUtility. Asking
     * PathUtility to prefix would make it derive the prefix from the request,
     * which on TYPO3 v14 means the system resource publisher and a fallback
     * to $GLOBALS['TYPO3_REQUEST']. That global is only populated by
     * middlewares that run inside this one, so the asset URL would depend on
     * somebody else's side effect. It also keeps this URL and the endpoint
     * URL derived from one source, which is the mismatch that made materialized
     * images resolve against the current page instead of the site root.
     */
    private function assetUrl(): string
    {
        return SitePath::absolute(
            PathUtility::getPublicResourceWebPath('EXT:'.Configuration::EXT_KEY.'/Resources/Public/JavaScript/file-sync.js', false),
        );
    }

    /**
     * The one lookup a settled installation pays for. Nothing above it
     * touches the body and nothing below it runs while the count is zero.
     * No runtime cache in front of it: this middleware is the only caller
     * and runs once per request, so that layer would never be read.
     *
     * @return array{storages: list<int>, count: int}
     */
    private function provisionalState(): array
    {
        $cache = $this->cacheManager->getCache('hash');
        $state = self::readState($cache);
        if (null !== $state) {
            return $state;
        }

        $storages = $this->storageService->getDeferredStorageUids();
        $state = ['storages' => $storages, 'count' => $this->fileRepository->countProvisional($storages)];
        $cache->set(self::CACHE_KEY, $state, [], self::CACHE_LIFETIME);

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
