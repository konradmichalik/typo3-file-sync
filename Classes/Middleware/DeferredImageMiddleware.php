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
use KonradMichalik\Typo3FileSync\Resource\Preview\PreviewStore;
use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, PublicUrlResolver, SitePath, StorageService};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Features;
use TYPO3\CMS\Core\Utility\PathUtility;

use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function base64_encode;
use function explode;
use function htmlspecialchars;
use function intval;
use function is_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strripos;
use function strtolower;
use function substr;
use function substr_replace;

/**
 * DeferredImageMiddleware.
 *
 * The outermost frontend middleware, so it is the last to see the response
 * and therefore the only place that also sees a body served straight from
 * the page cache. It marks every image that still points at a provisional
 * rendition and injects the module that asks the materialize endpoint to
 * replace them.
 *
 * Only a tag that states its own width and height takes part in the preview
 * stage. Where a preview of its rendition is already stored it is inlined as
 * a data URI, which is what makes every encounter after the first one cost
 * neither a preview request nor, for a tag the browser actually renders from
 * its src, a request for the grey placeholder. A tag carrying srcset takes no
 * part in the preview stage at all and still fetches the placeholder, because
 * the browser picks its candidate from there and ignores src entirely.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DeferredImageMiddleware implements MiddlewareInterface
{
    private const ATTRIBUTE = 'data-file-sync';

    private const ENDPOINT_ATTRIBUTE = 'data-file-sync-endpoint';

    /**
     * Carried only by an image that states its own size and whose preview is
     * still missing, so the module asks the preview stage for those and for
     * nothing else.
     */
    private const PREVIEW_ATTRIBUTE = 'data-file-sync-preview';

    private const PREVIEW_URI_PREFIX = 'data:image/webp;base64,';

    /**
     * Appended to the src of a tag that still points at a provisional
     * rendition. Neither "?" nor "-" occurs in the base64 alphabet, so this
     * can never turn up by accident inside an inlined preview.
     */
    private const PROVISIONAL_QUERY = 'file-sync-provisional=1';

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
        private PreviewStore $previewStore,
        private PublicUrlResolver $publicUrlResolver,
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

        $identifierByUrl = $this->publicUrlResolver->identifiersByUrl(array_values(array_unique($matches[2])), $storageUids);
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
            function (array $match) use ($identifierByUrl, $renditionByIdentifier, $skipSpans, $previewsEnabled, &$marked, &$previewByIdentifier): string {
                [$tag, $offset] = $match[0];
                if (self::isWithinSpan($offset, $skipSpans)) {
                    return $tag;
                }

                $identifier = $identifierByUrl[$match[2][0]] ?? null;
                $rendition = null === $identifier ? null : ($renditionByIdentifier[$identifier] ?? null);
                $rewritten = $this->withAttribute($tag, $match[1][0], $rendition['uid'] ?? null);
                if ($rewritten === $tag) {
                    return $tag;
                }

                ++$marked;
                // A tag that was marked had both of these, so the second half
                // of this narrows the types rather than deciding anything.
                if (!$previewsEnabled || null === $identifier || null === $rendition) {
                    return self::withProvisionalQuery($rewritten, $match[2], $offset);
                }

                // array_key_exists rather than ??=, because "there is no
                // preview" is the answer worth remembering: it is what a
                // freshly synced installation answers for every tag.
                if (!array_key_exists($identifier, $previewByIdentifier)) {
                    $previewByIdentifier[$identifier] = $this->storedPreview($rendition['storage'], $identifier);
                }

                return self::withPreview(
                    $rewritten,
                    $match[1][0],
                    $previewByIdentifier[$identifier],
                    $match[2],
                    $offset,
                );
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

        return self::appended($tag, ' '.self::ATTRIBUTE.'='.$quote.$this->deferredTokenService->create($processedFileUid).$quote);
    }

    /**
     * What an already marked tag gains from the preview store: the stored
     * preview in place of the URL the browser would otherwise fetch the grey
     * placeholder from, or the attribute that asks the module to go and get
     * one, or nothing at all, because a tag that states no size of its own
     * takes no part in the preview stage.
     *
     * Only the src value is replaced, between the quotes the tag already
     * carries, at the offsets the match reported: the quoting survives because
     * it is never touched, not because anything mirrors it. $quote is mirrored
     * by the marking branch alone, which appends an attribute of its own.
     *
     * The replacement still has to survive between those quotes, and it does:
     * a base64 payload behind a fixed prefix is alphanumerics, "+", "/", "=",
     * ":", ";", "," and ".", so neither quote character occurs in it.
     *
     * @param array{string, int} $src the matched src value and its offset in the body
     */
    private static function withPreview(string $tag, string $quote, ?string $preview, array $src, int $tagOffset): string
    {
        if (!self::declaresItsOwnSize($tag) || self::picksFromSrcset($tag)) {
            return self::withProvisionalQuery($tag, $src, $tagOffset);
        }

        if (null === $preview) {
            return self::withProvisionalQuery(
                self::appended($tag, ' '.self::PREVIEW_ATTRIBUTE.'='.$quote.'1'.$quote),
                $src,
                $tagOffset,
            );
        }

        return substr_replace(
            $tag,
            self::PREVIEW_URI_PREFIX.base64_encode($preview),
            $src[1] - $tagOffset,
            strlen($src[0]),
        );
    }

    /**
     * A processed filename is checksum-derived, so the "access plus 1 month"
     * expiry TYPO3 writes into public/.htaccess rests on its bytes never
     * changing. A deferred rendition breaks that: the placeholder and the
     * real file share one path, and only the bytes behind it change. Without
     * this suffix the reload after materialization is answered from the
     * placeholder the browser cached before the module had even run, for as
     * long as that month lasts.
     *
     * A fixed string is enough. It is added only while the tag is still
     * marked, and once the rendition is materialized the render emits the
     * plain URL, which that browser has never requested and therefore fetches
     * and caches fresh. Nothing here is per request, so a genuinely
     * materialized file keeps its long-lived cache entry.
     *
     * The same coordinate shape withPreview() uses: the src value is replaced
     * between the quotes the tag already carries, at the offsets the match
     * reported against the original body. Those survive appended(), which
     * only ever writes past the src span. It must never run on a tag
     * withPreview() inlines a data URI into, because the two would then
     * address one span through offsets taken against strings of different
     * lengths.
     *
     * The separator is escaped as "&amp;" rather than a bare "&" when a query
     * already exists, because this is HTML attribute content and TYPO3 itself
     * escapes the query strings it renders the same way: "?a=1&amp;b=2" is
     * what a multi-parameter src already looks like here, and matching that
     * convention costs nothing.
     *
     * A src carrying a fragment keeps it last, since a "#" is never sent to
     * the server and a query added after it would be part of the fragment
     * instead, silently fetching the same cached response the fragment was
     * supposed to bust.
     *
     * @param array{string, int} $src the matched src value and its offset in the body
     */
    private static function withProvisionalQuery(string $tag, array $src, int $tagOffset): string
    {
        [$path, $fragment] = explode('#', $src[0], 2) + [1 => ''];
        $separator = str_contains($path, '?') ? '&amp;' : '?';

        return substr_replace(
            $tag,
            $path.$separator.self::PROVISIONAL_QUERY.('' === $fragment ? '' : '#'.$fragment),
            $src[1] - $tagOffset,
            strlen($src[0]),
        );
    }

    /**
     * Whether the tag takes part in the preview stage at all.
     *
     * A stored preview is 32 pixels on its longest edge, while the grey
     * placeholder is generated at the rendition's own width and height. A tag
     * that states no size of its own is laid out from whatever its src turns
     * out to be, so a preview reaching it would collapse it to 32 pixels and
     * grow it back when the original lands: two layout shifts where the
     * placeholder alone costs none. That holds however the preview travels,
     * since the module assigns the very same data URI to src, so such a tag
     * is left with the placeholder and the original and nothing in between.
     *
     * The lookbehind is the one IMAGE_PATTERN uses on src=, for the same
     * reason: a word boundary also sits between the hyphen and the "w" of
     * data-width. An empty value states no size either.
     */
    private static function declaresItsOwnSize(string $tag): bool
    {
        return 1 === preg_match('/(?<![-\w])width=(["\'])[^"\']+\1/i', $tag)
            && 1 === preg_match('/(?<![-\w])height=(["\'])[^"\']+\1/i', $tag);
    }

    /**
     * Whether the browser takes this tag's image from a candidate list
     * rather than from src, in which case it never reads src at all. The
     * preview would then be a data URI nothing renders, and the tag would be
     * marked for the stage on every response: a source rendition downloaded
     * and a preview stored for a picture no visitor ever sees blurred.
     *
     * The same lookbehind as the size guard, for the same reason: a word
     * boundary also sits between the hyphen and the "s" of data-srcset, which
     * is a lazy-loading attribute the browser lays nothing out from. An
     * empty value names no candidate either.
     */
    private static function picksFromSrcset(string $tag): bool
    {
        return 1 === preg_match('/(?<![-\w])srcset=(["\'])[^"\']+\1/i', $tag);
    }

    /**
     * A store read is filesystem I/O, and this middleware sees every frontend
     * response there is: a preview that cannot be read must cost nothing more
     * than the request the browser would have made anyway, which is exactly
     * what a null answer here buys.
     */
    private function storedPreview(int $storageUid, string $identifier): ?string
    {
        try {
            return $this->previewStore->read($storageUid, $identifier);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Appends in front of the closing ">" and keeps a self-closing tag
     * self-closing.
     *
     * It must only ever change bytes after the src value: withPreview()
     * replaces that value at the offsets the match reported, and an
     * insertion anywhere before it would silently shift them.
     */
    private static function appended(string $tag, string $attribute): string
    {
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
