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

use function array_map;
use function count;
use function implode;
use function preg_match;
use function preg_split;
use function trim;

/**
 * SrcsetCandidates.
 *
 * A strict parser for the candidate list of a srcset attribute. It accepts
 * only what the extension's own rewriting needs and declines everything it
 * cannot be certain about, rather than approximating the full grammar of the
 * HTML Standard's "parse a srcset attribute" algorithm.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SrcsetCandidates
{
    /**
     * A candidate is a URL carrying neither whitespace nor a comma, since
     * either would make the split below ambiguous, followed by an optional
     * width or pixel density descriptor. The "h" descriptor once drafted for
     * this attribute never shipped, and a bare number without "w" or "x" is
     * not a descriptor at all, so both are left to fail this pattern rather
     * than named separately.
     */
    private const CANDIDATE_PATTERN = '/^([^\s,]+)(?:\s+(\d+w|\d+(?:\.\d+)?x))?$/';

    /**
     * @param list<array{url: string, descriptor: string|null}> $candidates
     */
    private function __construct(private array $candidates) {}

    /**
     * Splitting on a comma is only safe when every fragment it produces
     * parses as a candidate in its own right. A comma that belongs to the
     * URL itself, whether written unescaped in a filename or, structurally,
     * as part of a data: URI, produces a fragment with no descriptor
     * alongside one that has one. The one rule below, that a missing
     * descriptor is accepted only when it is the sole candidate, rejects
     * every one of those splits without a case for either, and without
     * naming data: URIs at all: their own "scheme;encoding," fragment never
     * ends in "w" or "x", so a list of more than one candidate containing
     * one is refused on the same rule that refuses a stray comma.
     */
    public static function parse(string $value): ?self
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $parts = preg_split('/,\s*/', $value);
        if (false === $parts) {
            return null;
        }

        $candidates = [];
        foreach ($parts as $part) {
            if (1 !== preg_match(self::CANDIDATE_PATTERN, $part, $matches)) {
                return null;
            }
            $candidates[] = ['url' => $matches[1], 'descriptor' => $matches[2] ?? null];
        }

        if (count($candidates) > 1 && self::hasCandidateWithoutDescriptor($candidates)) {
            return null;
        }

        return new self($candidates);
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_map(static fn (array $candidate): string => $candidate['url'], $this->candidates);
    }

    /**
     * Rebuilds the attribute value with each candidate's URL replaced by the
     * one at the same position in $urls, keeping its own descriptor. Spacing
     * is normalised to ", " between candidates, so a value round-tripped
     * through urls() and back is equivalent but not necessarily byte
     * identical to what was parsed.
     *
     * @param list<string> $urls one URL per candidate, in the order urls() returned them
     */
    public function withUrls(array $urls): string
    {
        $rebuilt = [];
        foreach ($this->candidates as $index => $candidate) {
            $url = $urls[$index] ?? $candidate['url'];
            $rebuilt[] = null === $candidate['descriptor'] ? $url : $url.' '.$candidate['descriptor'];
        }

        return implode(', ', $rebuilt);
    }

    /**
     * @param list<array{url: string, descriptor: string|null}> $candidates
     */
    private static function hasCandidateWithoutDescriptor(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (null === $candidate['descriptor']) {
                return true;
            }
        }

        return false;
    }
}
