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

use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, MaterializationService};

use function array_map;
use function array_merge;
use function count;
use function implode;
use function preg_match;
use function preg_replace_callback;

/**
 * SrcsetMarking.
 *
 * The resolved state of one tag's srcset attribute against the identifier
 * and rendition maps DeferredImageMiddleware has already built for the whole
 * body: which candidate is provisional, the token each one carries, and the
 * rewrite that suffixes every provisional candidate's URL.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SrcsetMarking
{
    /**
     * Stands in for a candidate this extension has nothing to do for, at the
     * position that candidate holds in the attribute. Neither digit nor dot
     * nor hex character, so it can never collide with a real token.
     */
    private const NOT_PROVISIONAL_TOKEN = '-';

    /**
     * Tokens carry only digits, a dot and hex, so a comma can join them
     * without ever needing to be escaped.
     */
    private const TOKEN_SEPARATOR = ',';

    /**
     * The same lookbehind DeferredImageMiddleware's own src pattern uses,
     * for the same reason: a word boundary also sits between the hyphen and
     * the "s" of data-srcset, which this must leave alone. An empty value is
     * matched too and left to SrcsetCandidates::parse() to decline, rather
     * than being treated here as "no srcset at all".
     */
    private const PATTERN = '/(?<![-\w])srcset=(["\'])([^"\']*)\1/i';

    /**
     * @param list<string> $tokens
     */
    private function __construct(
        private SrcsetCandidates $candidates,
        private string $quote,
        private array $tokens,
        public bool $hasProvisional,
    ) {}

    /**
     * Every candidate URL of every tag's srcset, gathered up front so
     * DeferredImageMiddleware can ask the database about them in the same
     * batch as every src rather than once per tag. A tag whose srcset does
     * not parse, or names more candidates than one materialize batch could
     * carry, contributes nothing here; resolve() parses it again later to
     * decide whether to decline the tag, which is cheap enough that
     * threading the parsed result through is not worth the state.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public static function urlsIn(array $tags): array
    {
        $urlLists = [];
        foreach ($tags as $tag) {
            if (1 !== preg_match(self::PATTERN, $tag, $match)) {
                continue;
            }

            $candidates = SrcsetCandidates::parse($match[2]);
            if (null === $candidates || count($candidates->urls()) > MaterializationService::MAX_TOKENS) {
                continue;
            }

            $urlLists[] = $candidates->urls();
        }

        return [] === $urlLists ? [] : array_merge(...$urlLists);
    }

    /**
     * @param array<string, string>                        $identifierByUrl
     * @param array<string, array{uid: int, storage: int}> $renditionByIdentifier
     *
     * @return self|false|null null when the tag carries no srcset attribute;
     *                         false when it carries one this parser declines,
     *                         which takes the whole tag down with it rather
     *                         than leaving src marked for a swap the browser
     *                         would never read from
     */
    public static function resolve(
        string $tag,
        array $identifierByUrl,
        array $renditionByIdentifier,
        DeferredTokenService $deferredTokenService,
    ): self|false|null {
        if (1 !== preg_match(self::PATTERN, $tag, $match)) {
            return null;
        }

        $candidates = SrcsetCandidates::parse($match[2]);
        if (null === $candidates || count($candidates->urls()) > MaterializationService::MAX_TOKENS) {
            return false;
        }

        $hasProvisional = false;
        $tokens = [];
        foreach ($candidates->urls() as $url) {
            $identifier = $identifierByUrl[$url] ?? null;
            $rendition = null === $identifier ? null : ($renditionByIdentifier[$identifier] ?? null);
            if (null === $rendition) {
                $tokens[] = self::NOT_PROVISIONAL_TOKEN;
                continue;
            }

            $hasProvisional = true;
            $tokens[] = $deferredTokenService->create($rendition['uid']);
        }

        return new self($candidates, $match[1], $tokens, $hasProvisional);
    }

    public function attributeValue(): string
    {
        return implode(self::TOKEN_SEPARATOR, $this->tokens);
    }

    /**
     * Every provisional candidate's URL is suffixed through $suffix, the
     * same transform a provisional src gets, so both stay one rule; a
     * non-provisional candidate is left exactly as it was. The attribute is
     * found fresh by pattern rather than through an offset, which is what
     * makes this safe to call after src has already been substituted into
     * the tag: nothing here depends on lengths measured before that
     * substitution ran.
     *
     * preg_replace_callback rather than preg_replace, because the
     * replacement is a value already built, not a template: a URL carrying
     * "$" or a backslash would otherwise be read as a backreference.
     *
     * @param callable(string): string $suffix
     */
    public function appliedTo(string $tag, callable $suffix): string
    {
        $urls = array_map(
            static fn (string $url, string $token): string => self::NOT_PROVISIONAL_TOKEN === $token ? $url : $suffix($url),
            $this->candidates->urls(),
            $this->tokens,
        );

        return (string) preg_replace_callback(
            self::PATTERN,
            fn (): string => 'srcset='.$this->quote.$this->candidates->withUrls($urls).$this->quote,
            $tag,
            1,
        );
    }
}
