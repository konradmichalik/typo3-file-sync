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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Middleware;

use Generator;
use KonradMichalik\Typo3FileSync\Middleware\SrcsetCandidates;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * SrcsetCandidatesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(SrcsetCandidates::class)]
final class SrcsetCandidatesTest extends TestCase
{
    #[Test]
    public function widthDescriptorsAreParsed(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg 400w, b.jpg 800w');

        self::assertNotNull($candidates);
        self::assertSame(['a.jpg', 'b.jpg'], $candidates->urls());
    }

    #[Test]
    public function densityDescriptorsAreParsed(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg 1x, b.jpg 2x');

        self::assertNotNull($candidates);
        self::assertSame(['a.jpg', 'b.jpg'], $candidates->urls());
    }

    #[Test]
    public function fractionalDensityDescriptorIsParsed(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg 1.5x');

        self::assertNotNull($candidates);
        self::assertSame(['a.jpg'], $candidates->urls());
    }

    #[Test]
    public function singleCandidateWithoutADescriptorIsParsed(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg');

        self::assertNotNull($candidates);
        self::assertSame(['a.jpg'], $candidates->urls());
    }

    #[Test]
    public function aSoleCandidateNeedsNoDescriptorEvenIfItLooksLikeOne(): void
    {
        $candidates = SrcsetCandidates::parse('400w');

        self::assertNotNull($candidates);
        self::assertSame(['400w'], $candidates->urls());
    }

    #[Test]
    public function missingWhitespaceAfterTheCommaIsParsed(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg 400w,b.jpg 800w');

        self::assertNotNull($candidates);
        self::assertSame(['a.jpg', 'b.jpg'], $candidates->urls());
    }

    #[Test]
    public function surroundingWhitespaceIsIgnored(): void
    {
        $candidates = SrcsetCandidates::parse("  a.jpg 400w, b.jpg 800w  \n");

        self::assertNotNull($candidates);
        self::assertSame(['a.jpg', 'b.jpg'], $candidates->urls());
    }

    #[Test]
    public function aQueryStringInsideTheUrlIsPreserved(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg?w=400 400w, a.jpg?w=800 800w');

        self::assertNotNull($candidates);
        self::assertSame(['a.jpg?w=400', 'a.jpg?w=800'], $candidates->urls());
    }

    #[Test]
    #[DataProvider('declinedValues')]
    public function declinedShapeYieldsNull(string $value): void
    {
        self::assertNull(SrcsetCandidates::parse($value));
    }

    /**
     * Every shape here is declined by the same rule: a candidate without a
     * descriptor is only accepted when it is the sole candidate. That one
     * rule is enough to reject an unescaped comma inside a filename and a
     * data: URI without a special case for either, because both split into
     * a fragment carrying no descriptor alongside one that does.
     */
    public static function declinedValues(): Generator
    {
        yield 'empty string' => [''];
        yield 'whitespace only' => ["  \n\t"];
        yield 'h descriptor, once removed from the spec draft' => ['a.jpg 400h'];
        yield 'width descriptor without a unit' => ['a.jpg 400'];
        yield 'unescaped comma in a filename' => ['photo,2.jpg 400w, photo3.jpg 800w'];
        yield 'data URI, whose own comma is not a candidate separator' => ['data:image/webp;base64,UklGRhoAAABXRUJQ 1x'];
        yield 'trailing comma' => ['a.jpg 400w,'];
    }

    #[Test]
    public function roundTripReproducesTheSameCandidatesWithNormalisedSpacing(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg 400w,b.jpg 800w');

        self::assertNotNull($candidates);
        self::assertSame('a.jpg 400w, b.jpg 800w', $candidates->withUrls($candidates->urls()));
    }

    #[Test]
    public function roundTripOfASingleCandidateKeepsTheAbsentDescriptor(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg');

        self::assertNotNull($candidates);
        self::assertSame('a.jpg', $candidates->withUrls($candidates->urls()));
    }

    #[Test]
    public function withUrlsSubstitutesUrlsWhileKeepingDescriptors(): void
    {
        $candidates = SrcsetCandidates::parse('a.jpg 400w, b.jpg 800w');

        self::assertNotNull($candidates);
        self::assertSame('x.jpg 400w, y.jpg 800w', $candidates->withUrls(['x.jpg', 'y.jpg']));
    }
}
