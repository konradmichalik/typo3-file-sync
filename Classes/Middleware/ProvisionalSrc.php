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
 * What a tag's own src attribute gets rewritten to, independent of whatever
 * its srcset needs (see SrcsetMarking): a stored preview inlined as a data
 * URI, or the query suffix that busts the browser's cache once
 * materialization replaces the same URL's bytes.
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
    public static function withPreview(string $tag, string $quote, ?string $preview, array $src, int $tagOffset): string
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
     * The lookbehind is the one DeferredImageMiddleware's own src pattern
     * uses, for the same reason: a word boundary also sits between the
     * hyphen and the "w" of data-width. An empty value states no size
     * either.
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
}
