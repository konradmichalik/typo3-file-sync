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

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Service\{PublicUrlResolver, SitePath, StorageService};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\Utility\PathUtility;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function htmlspecialchars;
use function intval;
use function is_array;
use function is_string;
use function preg_match_all;
use function preg_replace_callback;
use function str_starts_with;
use function strlen;
use function strripos;
use function strtolower;
use function substr;

/**
 * DeferredImageMiddleware.
 *
 * The outermost frontend middleware, so it is the last to see the response
 * and therefore the only place that also sees a body served straight from
 * the page cache. It marks every image that still points at a provisional
 * rendition and injects the module that asks the materialize endpoint to
 * replace them. What each tag's own markup becomes is TagRewriter's decision,
 * kept in its own class for the same reason SrcsetMarking and ProvisionalSrc
 * are: this middleware's own job is the response, the provisional-count
 * cache and the injected snippet, not the per-tag rewrite rules.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DeferredImageMiddleware implements MiddlewareInterface
{
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
     *
     * The second alternative is a picture's source: it names its candidates
     * through srcset rather than src, so its own quote is captured in group
     * 3 instead of group 1, which TagRewriter falls back to whenever group 1
     * did not participate. A video or audio source names its file through
     * src instead, which is why this requires srcset rather than matching
     * every source element there is; one carrying both is not a shape any
     * renderer this extension supports produces.
     */
    private const IMAGE_PATTERN = '/<img\b[^>]*(?<![-\w])src=(["\'])([^"\']+)\1[^>]*>|<source\b[^>]*(?<![-\w])srcset=(["\'])[^"\']*\3[^>]*\/?>/i';

    /**
     * Spans whose contents are not markup the browser renders as elements.
     * An img inside them must be left alone: in a script an injected
     * attribute can terminate a JavaScript string literal, and in a
     * textarea it would show up as visible page text.
     */
    private const SKIP_PATTERN = '/<script\b[^>]*>.*?<\/script\s*>|<textarea\b[^>]*>.*?<\/textarea\s*>|<!--.*?-->/is';

    /**
     * A source's preview never reaches anywhere a visitor sees it, the same
     * as an unresolved srcset's, but for a different reason: the browser
     * renders whichever source matches or, failing all of them, the img
     * itself, never both, so a preview stored for one crop while another is
     * shown is pure waste. This is what tells TagRewriter an img sits inside
     * a picture too, not only what marks the source itself, since an img
     * with its own width and height would otherwise pass the same preview
     * gate a bare responsive img does.
     */
    private const PICTURE_PATTERN = '/<picture\b[^>]*>.*?<\/picture\s*>/is';

    public function __construct(
        private CacheManager $cacheManager,
        private Features $features,
        private FileRepository $fileRepository,
        private PublicUrlResolver $publicUrlResolver,
        private StorageService $storageService,
        private StreamFactoryInterface $streamFactory,
        private TagRewriter $tagRewriter,
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

        // $matches[2] is empty for a source match: the pattern's src group
        // only ever participates for an img.
        $urls = array_merge(array_filter($matches[2], static fn (string $url): bool => '' !== $url), SrcsetMarking::urlsIn($matches[0]));
        $identifierByUrl = $this->publicUrlResolver->identifiersByUrl(array_values(array_unique($urls)), $storageUids);
        if ([] === $identifierByUrl) {
            return null;
        }

        $renditionByIdentifier = $this->fileRepository->findProvisionalProcessedFiles(
            $storageUids,
            array_values(array_unique(array_values($identifierByUrl))),
        );
        if ([] === $renditionByIdentifier) {
            return null;
        }

        $rewritten = $this->rewriteTags($body, $identifierByUrl, $renditionByIdentifier);
        if (null === $rewritten) {
            return null;
        }

        return $this->injectSnippet($rewritten);
    }

    /**
     * Matched offsets are needed to tell a tag the browser renders from one
     * sitting inside a script, a textarea or a comment.
     *
     * @param array<string, string>                        $identifierByUrl
     * @param array<string, array{uid: int, storage: int}> $renditionByIdentifier
     *
     * @return string|null the rewritten body, or null when nothing was marked
     */
    private function rewriteTags(string $body, array $identifierByUrl, array $renditionByIdentifier): ?string
    {
        $skipSpans = self::skipSpans($body);
        $pictureSpans = self::spans(self::PICTURE_PATTERN, $body);
        // Resolved once for the whole body rather than per tag, and only
        // after a provisional rendition was actually found, so a response
        // that ends up untouched never asks.
        $previewsEnabled = $this->features->isFeatureEnabled(Configuration::FEATURE_PREVIEW_IMAGES);
        // Ten copies of one image on a page are ten tags but one rendition, so
        // they are one store read. markBody() already dedupes before the
        // query; this is the same dedupe for the filesystem behind it.
        /** @var array<string, string|null> $previewByIdentifier */
        $previewByIdentifier = [];
        $marked = 0;
        $total = 0;
        $result = preg_replace_callback(
            self::IMAGE_PATTERN,
            function (array $match) use ($identifierByUrl, $renditionByIdentifier, $skipSpans, $pictureSpans, $previewsEnabled, &$marked, &$previewByIdentifier): string {
                [$tag, $offset] = $match[0];
                if (self::isWithinSpan($offset, $skipSpans)) {
                    return $tag;
                }

                $insidePicture = self::isWithinSpan($offset, $pictureSpans);
                $outcome = $this->tagRewriter->rewriteTag($match, $offset, $identifierByUrl, $renditionByIdentifier, $previewsEnabled, $insidePicture, $previewByIdentifier);
                if (null === $outcome) {
                    return $tag;
                }

                [$rewritten, $previewByIdentifier] = $outcome;
                ++$marked;

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
     * @return list<array{int, int}> start and end offset of each span
     */
    private static function skipSpans(string $body): array
    {
        return self::spans(self::SKIP_PATTERN, $body);
    }

    /**
     * @return list<array{int, int}> start and end offset of each span
     */
    private static function spans(string $pattern, string $body): array
    {
        preg_match_all($pattern, $body, $matches, \PREG_OFFSET_CAPTURE);

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
