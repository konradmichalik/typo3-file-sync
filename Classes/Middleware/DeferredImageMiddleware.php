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
use function array_merge;
use function array_unique;
use function array_values;
use function htmlspecialchars;
use function intval;
use function is_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
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
 * A srcset attribute is marked for the original stage on the same terms as
 * src: every candidate that names a provisional rendition gets a token of
 * its own in data-file-sync-srcset, positionally, with "-" standing in for a
 * candidate this extension has nothing to do for. src keeps data-file-sync
 * when it is itself provisional; a tag whose src already resolved but whose
 * srcset has not gets the literal marker "srcset" there instead, since the
 * browser never reads src once srcset is present and a token on it would
 * name a rendition nothing renders from. A srcset this middleware cannot
 * confidently parse, or one that names more candidates than one materialize
 * batch could ever carry, takes the whole tag down with it: src is left
 * exactly as it was rather than marked for a swap the browser would ignore.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DeferredImageMiddleware implements MiddlewareInterface
{
    private const ATTRIBUTE = 'data-file-sync';

    /**
     * Carries one entry per srcset candidate, positionally, so the module
     * can rebuild the attribute without having to re-parse it against the
     * URLs still sitting in srcset itself.
     */
    private const SRCSET_ATTRIBUTE = 'data-file-sync-srcset';

    /**
     * What ATTRIBUTE carries instead of a token when src itself did not
     * resolve to a provisional rendition but the srcset did. The browser
     * never reads src once srcset is present, so a token there would name a
     * rendition nothing renders from; this tells the module to look at
     * SRCSET_ATTRIBUTE instead of trying to resolve ATTRIBUTE as one.
     */
    private const SRCSET_MARKER = 'srcset';

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

        $urls = array_merge($matches[2], SrcsetMarking::urlsIn($matches[0]));
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

                $outcome = $this->rewriteTag($match, $offset, $identifierByUrl, $renditionByIdentifier, $previewsEnabled, $previewByIdentifier);
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
     * Rewrites markup this extension does not own, so it declines every tag
     * it is not certain about: one that is already marked, and one whose
     * quotes do not balance, which means the pattern stopped at a ">" inside
     * an attribute value and the match is only part of the real tag.
     *
     * Checked before anything about src or srcset is resolved, because a
     * declined tag must not have either substituted into it at all.
     */
    private static function isDeclined(string $tag): bool
    {
        return str_contains(strtolower($tag), self::ATTRIBUTE) || self::hasUnbalancedQuotes($tag);
    }

    /**
     * The single per-tag decision rewriteTags()'s callback delegates to, so
     * that closure stays a dispatcher rather than carrying every branch
     * itself. Returns null for every reason a tag is left untouched: it is
     * declined outright, its srcset does not parse, or neither src nor any
     * srcset candidate turned out to be provisional.
     *
     * $previewByIdentifier travels by value rather than by reference: it
     * comes back as the second element of the tuple, for the caller, which
     * owns the variable across every tag, to carry into the next one.
     *
     * @param array{0: array{string, int}, 1: array{string, int}, 2: array{string, int}} $match
     * @param array<string, string>                                                      $identifierByUrl
     * @param array<string, array{uid: int, storage: int}>                               $renditionByIdentifier
     * @param array<string, string|null>                                                 $previewByIdentifier
     *
     * @return array{0: string, 1: array<string, string|null>}|null
     */
    private function rewriteTag(
        array $match,
        int $offset,
        array $identifierByUrl,
        array $renditionByIdentifier,
        bool $previewsEnabled,
        array $previewByIdentifier,
    ): ?array {
        [$tag] = $match[0];
        if (self::isDeclined($tag)) {
            return null;
        }

        $srcset = SrcsetMarking::resolve($tag, $identifierByUrl, $renditionByIdentifier, $this->deferredTokenService);
        if (false === $srcset) {
            return null;
        }

        $srcsetHasProvisional = null !== $srcset && $srcset->hasProvisional;

        $identifier = $identifierByUrl[$match[2][0]] ?? null;
        $rendition = null === $identifier ? null : ($renditionByIdentifier[$identifier] ?? null);
        if (null === $rendition && !$srcsetHasProvisional) {
            return null;
        }

        $rewritten = $tag;
        if (null !== $rendition) {
            [$rewritten, $previewByIdentifier] = $this->rewriteSrc($tag, $match, $offset, $identifier, $rendition, $previewsEnabled, $previewByIdentifier);
        }

        if ($srcsetHasProvisional) {
            $rewritten = $srcset->appliedTo($rewritten, ProvisionalSrc::suffixedWithProvisionalQuery(...));
        }

        $fileSyncValue = null !== $rendition ? $this->deferredTokenService->create($rendition['uid']) : self::SRCSET_MARKER;
        $srcsetValue = $srcsetHasProvisional ? $srcset->attributeValue() : null;

        return [self::withMarkerAttributes($rewritten, $match[1][0], $fileSyncValue, $srcsetValue), $previewByIdentifier];
    }

    /**
     * src's own half of rewriteTag(): the offset-based substitution that
     * inlines a preview or appends the provisional query, unconditional on
     * whatever the srcset half decides. $identifier is never null here: it
     * is only null when $rendition is, and rewriteTag() only calls this
     * once $rendition is known to be an array.
     *
     * @param array{0: array{string, int}, 1: array{string, int}, 2: array{string, int}} $match
     * @param array{uid: int, storage: int}                                              $rendition
     * @param array<string, string|null>                                                 $previewByIdentifier
     *
     * @return array{0: string, 1: array<string, string|null>}
     */
    private function rewriteSrc(
        string $tag,
        array $match,
        int $offset,
        string $identifier,
        array $rendition,
        bool $previewsEnabled,
        array $previewByIdentifier,
    ): array {
        if (!$previewsEnabled) {
            return [ProvisionalSrc::withProvisionalQuery($tag, $match[2], $offset), $previewByIdentifier];
        }

        // array_key_exists rather than ??=, because "there is no preview" is
        // the answer worth remembering: it is what a freshly synced
        // installation answers for every tag.
        if (!array_key_exists($identifier, $previewByIdentifier)) {
            $previewByIdentifier[$identifier] = $this->storedPreview($rendition['storage'], $identifier);
        }

        return [ProvisionalSrc::withPreview($tag, $match[1][0], $previewByIdentifier[$identifier], $match[2], $offset), $previewByIdentifier];
    }

    /**
     * Appended last, once src and srcset have already been substituted at
     * whatever offsets or patterns they each needed: appended() only ever
     * writes past the end of what is already there, so nothing about the
     * order relative to those two substitutions matters except that this
     * one comes after both.
     *
     * The attributes reuse the quote character the tag already uses for its
     * src. A tag written with single quotes is the one that turns up inside
     * a double-quoted JavaScript string literal, where injecting a double
     * quote would end the string and break the whole script block. Neither
     * value needs escaping: a token is digits, a dot and hex, the srcset
     * marker is a bare word, and srcset tokens are joined by a comma.
     */
    private static function withMarkerAttributes(string $tag, string $quote, string $fileSyncValue, ?string $srcsetValue): string
    {
        $attribute = ' '.self::ATTRIBUTE.'='.$quote.$fileSyncValue.$quote;
        if (null !== $srcsetValue) {
            $attribute .= ' '.self::SRCSET_ATTRIBUTE.'='.$quote.$srcsetValue.$quote;
        }

        return ProvisionalSrc::appended($tag, $attribute);
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
