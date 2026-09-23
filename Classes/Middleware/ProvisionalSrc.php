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

use function base64_encode;
use function explode;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function strlen;
use function substr;
use function substr_replace;

/**
 * ProvisionalSrc.
 *
 * What a tag's own src attribute gets rewritten to: the query suffix that
 * busts the browser's cache once materialization replaces the same URL's
 * bytes, or, once DeferredImageMiddleware has decided a preview applies and
 * built its data URI, that URI inlined either here or, per SrcsetMarking,
 * into srcset instead.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ProvisionalSrc
{
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

    /**
     * The stored preview inlined in place of the URL the browser would
     * otherwise fetch the grey placeholder from. Callers decide whether a
     * preview even applies to this tag before reaching for this: it never
     * inspects the tag itself.
     *
     * Only the src value is replaced, between the quotes the tag already
     * carries, at the offsets the match reported: the quoting survives
     * because it is never touched, not because anything mirrors it.
     *
     * The replacement still has to survive between those quotes, and it does:
     * a base64 payload behind a fixed prefix is alphanumerics, "+", "/", "=",
     * ":", ";", "," and ".", so neither quote character occurs in it.
     *
     * @param array{string, int} $src the matched src value and its offset in the body
     */
    public static function withPreviewData(string $tag, array $src, int $tagOffset, string $preview): string
    {
        return substr_replace($tag, self::previewUri($preview), $src[1] - $tagOffset, strlen($src[0]));
    }

    /**
     * The same data URI withPreviewData() inlines into src, exposed on its
     * own so SrcsetMarking::appliedToWithPreview() can inline it into srcset
     * instead: one tag never gets a preview through both.
     */
    public static function previewUri(string $preview): string
    {
        return self::PREVIEW_URI_PREFIX.base64_encode($preview);
    }

    /**
     * The attribute that asks the module to fetch a preview for this tag,
     * ready to append. Added at most once per tag regardless of whether the
     * preview would end up on src or on srcset, since D6 has the whole tag
     * share one preview.
     */
    public static function previewMarkerAttribute(string $quote): string
    {
        return ' '.self::PREVIEW_ATTRIBUTE.'='.$quote.'1'.$quote;
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
    public static function withProvisionalQuery(string $tag, array $src, int $tagOffset): string
    {
        return substr_replace($tag, self::suffixedWithProvisionalQuery($src[0]), $src[1] - $tagOffset, strlen($src[0]));
    }

    /**
     * The string transform withProvisionalQuery() applies to src, extracted
     * so a srcset candidate can be suffixed the same way without a tag or an
     * offset to substitute it into: SrcsetMarking::appliedTo() rebuilds the
     * whole attribute value instead, through SrcsetCandidates::withUrls().
     */
    public static function suffixedWithProvisionalQuery(string $url): string
    {
        [$path, $fragment] = explode('#', $url, 2) + [1 => ''];
        $separator = str_contains($path, '?') ? '&amp;' : '?';

        return $path.$separator.self::PROVISIONAL_QUERY.('' === $fragment ? '' : '#'.$fragment);
    }

    /**
     * Appends in front of the closing ">" and keeps a self-closing tag
     * self-closing.
     *
     * It must only ever change bytes after the src value: withPreview()
     * replaces that value at the offsets the match reported, and an
     * insertion anywhere before it would silently shift them. Public because
     * DeferredImageMiddleware's own marker attributes are appended the same
     * way, once src and srcset have both already been substituted.
     */
    public static function appended(string $tag, string $attribute): string
    {
        $head = rtrim(substr($tag, 0, -1));
        if (str_ends_with($head, '/')) {
            return rtrim(substr($head, 0, -1)).$attribute.' />';
        }

        return $head.$attribute.'>';
    }

    /**
     * Whether the tag takes part in the preview stage at all, whether that
     * preview would land on src or, per SrcsetMarking, on srcset instead.
     *
     * A stored preview is 32 pixels on its longest edge, while the grey
     * placeholder is generated at the rendition's own width and height. A tag
     * that states no size of its own is laid out from whatever its image
     * turns out to be, so a preview reaching it would collapse it to 32
     * pixels and grow it back when the original lands: two layout shifts
     * where the placeholder alone costs none.
     *
     * The lookbehind is the one DeferredImageMiddleware's own src pattern
     * uses, for the same reason: a word boundary also sits between the
     * hyphen and the "w" of data-width. An empty value states no size
     * either.
     */
    public static function declaresItsOwnSize(string $tag): bool
    {
        return 1 === preg_match('/(?<![-\w])width=(["\'])[^"\']+\1/i', $tag)
            && 1 === preg_match('/(?<![-\w])height=(["\'])[^"\']+\1/i', $tag);
    }
}
