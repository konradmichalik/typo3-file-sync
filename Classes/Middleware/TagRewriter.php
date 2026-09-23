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

use KonradMichalik\Typo3FileSync\Resource\Preview\PreviewStore;
use KonradMichalik\Typo3FileSync\Service\DeferredTokenService;
use Throwable;

use function array_key_exists;
use function preg_match;
use function preg_replace;
use function str_contains;
use function strtolower;

/**
 * TagRewriter.
 *
 * The single per-tag decision DeferredImageMiddleware's rewrite loop
 * delegates to for every img tag it matches: whether the tag is markable at
 * all, what its src and srcset each become, and whether a preview applies to
 * either. Split out from the middleware itself to keep that class to its own
 * concerns (the HTTP response, the provisional-count cache, the snippet) and
 * PHPStan's cognitive complexity budget to what one class of decisions
 * actually needs.
 *
 * A srcset attribute is marked on the same terms as src: every candidate
 * that names a provisional rendition gets a token of its own in
 * data-file-sync-srcset, positionally, with the candidate's own URL standing
 * in for one this extension has nothing to do for. src keeps data-file-sync
 * when it is itself provisional; a tag whose src already resolved but whose
 * srcset has not gets the literal marker "srcset" there instead, since the
 * browser never reads src once srcset is present and a token on it would
 * name a rendition nothing renders from. A srcset this middleware cannot
 * confidently parse, or one that names more candidates than one materialize
 * batch could ever carry, takes the whole tag down with it: src is left
 * exactly as it was rather than marked for a swap the browser would ignore.
 *
 * Only a tag that states its own width and height takes part in the preview
 * stage. Where a preview of its rendition is already stored it is inlined as
 * a data URI, which is what makes every encounter after the first one cost
 * neither a preview request nor, for a tag the browser actually renders from
 * its src, a request for the grey placeholder. D6: a tag has one preview,
 * built from src's own rendition when src is provisional, otherwise from the
 * first provisional srcset candidate. D7: the browser never reads src once
 * srcset is present, so that one preview is shown through srcset instead of
 * src whenever a srcset attribute exists at all, as its sole candidate; the
 * real candidate list survives in data-file-sync-srcset regardless.
 *
 * D8: a picture's source is marked the same way a responsive img's srcset
 * is, since it names its candidates the same way and carries no src of its
 * own, but it and the picture's own img both take no part in the preview
 * stage at all: the browser renders whichever source matches or, failing
 * every one of them, the img, never more than one, so a preview stored for
 * a crop that particular visitor may never see is pure waste. The img
 * still gets marked and swapped through its own src exactly like any other;
 * only the preview decision changes.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class TagRewriter
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

    public function __construct(
        private DeferredTokenService $deferredTokenService,
        private PreviewStore $previewStore,
    ) {}

    /**
     * The single per-tag decision DeferredImageMiddleware's rewrite callback
     * delegates to, so that closure stays a dispatcher rather than carrying
     * every branch itself. Returns null for every reason a tag is left
     * untouched: it is declined outright, its srcset does not parse, or
     * neither src nor any srcset candidate turned out to be provisional.
     *
     * $previewByIdentifier travels by value rather than by reference: it
     * comes back as the second element of the tuple, for the caller, which
     * owns the variable across every tag, to carry into the next one.
     *
     * A picture's source matches the same pattern as an img, in its second
     * alternative: group 1 (src's quote) and group 2 (src's value) never
     * participate for it, so $identifier resolves to null and $rendition
     * stays null the same way it would for an img whose src nobody
     * recognised. Group 3 (srcset's own quote) is what the fallback below
     * uses for the new attributes it appends instead; PCRE drops a trailing
     * group entirely, rather than padding it empty, whenever the alternative
     * that owns it did not match, which is why it is optional here and img's
     * own match array simply has no fourth element at all.
     *
     * @param array{0: array{string, int}, 1: array{string, int}, 2: array{string, int}, 3?: array{string, int}} $match
     * @param array<string, string>                                                                              $identifierByUrl
     * @param array<string, array{uid: int, storage: int}>                                                       $renditionByIdentifier
     * @param array<string, string|null>                                                                         $previewByIdentifier
     *
     * @return array{0: string, 1: array<string, string|null>}|null
     */
    public function rewriteTag(
        array $match,
        int $offset,
        array $identifierByUrl,
        array $renditionByIdentifier,
        bool $previewsEnabled,
        bool $insidePicture,
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

        $identifier = $identifierByUrl[$match[2][0]] ?? null;
        $rendition = null === $identifier ? null : ($renditionByIdentifier[$identifier] ?? null);
        $srcsetHasProvisional = null !== $srcset && $srcset->hasProvisional;
        if (null === $rendition && !$srcsetHasProvisional) {
            return null;
        }

        $quote = '' !== $match[1][0] ? $match[1][0] : ($match[3][0] ?? '');

        // D7: the browser never reads src once a srcset attribute is
        // present, whatever it contains, so a preview only ever lands on
        // src when there is no srcset at all to read from instead.
        $showsPreviewOnSrc = null !== $rendition && null === $srcset;
        [$preview, $previewEligible, $previewByIdentifier] = $this->resolvePreview(
            $showsPreviewOnSrc,
            $srcsetHasProvisional,
            $previewsEnabled,
            $insidePicture,
            $tag,
            $identifier,
            $rendition,
            $srcset,
            $previewByIdentifier,
        );

        $rewritten = self::rewriteSrc($tag, $match, $offset, $rendition, $showsPreviewOnSrc, $preview);
        $rewritten = self::rewriteSrcset($rewritten, $srcset, $preview);

        if ($previewEligible && null === $preview) {
            $rewritten = ProvisionalSrc::appended($rewritten, ProvisionalSrc::previewMarkerAttribute($quote));
        }

        [$fileSyncValue, $srcsetValue] = $this->markerValues($rendition, $srcsetHasProvisional, $srcset);

        return [self::withMarkerAttributes($rewritten, $quote, $fileSyncValue, $srcsetValue), $previewByIdentifier];
    }

    /**
     * The values data-file-sync and data-file-sync-srcset get once a tag is
     * known to need at least one of them: src's own token when src is
     * provisional, the literal "srcset" marker otherwise, and the full
     * candidate list only when srcset actually has something provisional in
     * it.
     *
     * @param array{uid: int, storage: int}|null $rendition
     *
     * @return array{0: string, 1: string|null}
     */
    private function markerValues(?array $rendition, bool $srcsetHasProvisional, ?SrcsetMarking $srcset): array
    {
        $fileSyncValue = null !== $rendition ? $this->deferredTokenService->create($rendition['uid']) : self::SRCSET_MARKER;
        $srcsetValue = $srcsetHasProvisional && null !== $srcset ? $srcset->attributeValue() : null;

        return [$fileSyncValue, $srcsetValue];
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
     * The pattern stopped at a ">" inside an attribute value when the quotes
     * no longer balance, which means the match is only part of the real tag.
     */
    private static function hasUnbalancedQuotes(string $tag): bool
    {
        return 1 === preg_match('/["\']/', (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $tag));
    }

    /**
     * Whether this tag gets a preview at all and, once fetched, its bytes.
     * D6's size gate applies to the whole tag: an unsized tag reaches this
     * as ineligible regardless of what src or srcset would otherwise offer.
     *
     * $insidePicture forces the same ineligibility on a sized img: the
     * browser renders whichever source matches or, failing all of them, the
     * img, never both, so a preview reaching one is stored for a crop no
     * visitor is guaranteed to ever see. A source itself never reaches this
     * as eligible regardless, since it carries neither width nor height of
     * its own for declaresItsOwnSize() to find; $insidePicture exists for
     * the img sitting beside it, not for the source.
     *
     * @param array{uid: int, storage: int}|null $rendition
     * @param array<string, string|null>         $previewByIdentifier
     *
     * @return array{0: string|null, 1: bool, 2: array<string, string|null>}
     */
    private function resolvePreview(
        bool $showsPreviewOnSrc,
        bool $srcsetHasProvisional,
        bool $previewsEnabled,
        bool $insidePicture,
        string $tag,
        ?string $identifier,
        ?array $rendition,
        ?SrcsetMarking $srcset,
        array $previewByIdentifier,
    ): array {
        $eligible = !$insidePicture
            && ($showsPreviewOnSrc || $srcsetHasProvisional)
            && $previewsEnabled
            && ProvisionalSrc::declaresItsOwnSize($tag);
        if (!$eligible) {
            return [null, false, $previewByIdentifier];
        }

        // D6: one preview per tag, built from src's own rendition whenever
        // src is itself provisional, since that is available at zero extra
        // cost and matches what a plain image already did; otherwise from
        // the first provisional srcset candidate, which $eligible already
        // guarantees exists whenever $rendition does not.
        $source = null !== $rendition && null !== $identifier
            ? ['identifier' => $identifier, 'rendition' => $rendition]
            : $srcset?->firstProvisional;
        if (null === $source) {
            // Unreachable: see the comment above.
            return [null, true, $previewByIdentifier];
        }

        [$preview, $previewByIdentifier] = $this->preview($source, $previewByIdentifier);

        return [$preview, true, $previewByIdentifier];
    }

    /**
     * @param array{identifier: string, rendition: array{uid: int, storage: int}} $source
     * @param array<string, string|null>                                          $previewByIdentifier
     *
     * @return array{0: string|null, 1: array<string, string|null>}
     */
    private function preview(array $source, array $previewByIdentifier): array
    {
        $identifier = $source['identifier'];
        // array_key_exists rather than ??=, because "there is no preview" is
        // the answer worth remembering: it is what a freshly synced
        // installation answers for every tag.
        if (!array_key_exists($identifier, $previewByIdentifier)) {
            $previewByIdentifier[$identifier] = $this->storedPreview($source['rendition']['storage'], $identifier);
        }

        return [$previewByIdentifier[$identifier], $previewByIdentifier];
    }

    /**
     * A store read is filesystem I/O, and DeferredImageMiddleware sees every
     * frontend response there is: a preview that cannot be read must cost
     * nothing more than the request the browser would have made anyway,
     * which is exactly what a null answer here buys.
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
     * src's own substitution: a preview only when this tag shows its preview
     * through src rather than srcset and one was actually found. An unsized
     * tag or one that has not settled yet reaches this with $preview still
     * null and falls back to the query suffix, as it always did.
     *
     * @param array{0: array{string, int}, 1: array{string, int}, 2: array{string, int}} $match
     * @param array{uid: int, storage: int}|null                                         $rendition
     */
    private static function rewriteSrc(string $tag, array $match, int $offset, ?array $rendition, bool $showsPreviewOnSrc, ?string $preview): string
    {
        if (null === $rendition) {
            return $tag;
        }

        return $showsPreviewOnSrc && null !== $preview
            ? ProvisionalSrc::withPreviewData($tag, $match[2], $offset, $preview)
            : ProvisionalSrc::withProvisionalQuery($tag, $match[2], $offset);
    }

    /**
     * srcset's own substitution, symmetrical to rewriteSrc(): nothing to do
     * without a provisional candidate, the shared preview once one was
     * found, or the usual per-candidate query suffix otherwise.
     */
    private static function rewriteSrcset(string $tag, ?SrcsetMarking $srcset, ?string $preview): string
    {
        if (null === $srcset || !$srcset->hasProvisional) {
            return $tag;
        }

        return null !== $preview
            ? $srcset->appliedToWithPreview($tag, ProvisionalSrc::previewUri($preview))
            : $srcset->appliedTo($tag, ProvisionalSrc::suffixedWithProvisionalQuery(...));
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
}
