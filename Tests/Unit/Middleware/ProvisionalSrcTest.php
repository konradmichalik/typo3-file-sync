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
use KonradMichalik\Typo3FileSync\Middleware\ProvisionalSrc;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * ProvisionalSrcTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ProvisionalSrc::class)]
final class ProvisionalSrcTest extends TestCase
{
    #[Test]
    public function withPreviewDataInlinesTheDataUriBetweenTheExistingQuotes(): void
    {
        $tag = '<img src="orig.jpg" width="10" height="10">';
        $src = ['orig.jpg', 10];

        $result = ProvisionalSrc::withPreviewData($tag, $src, 0, 'bytes');

        self::assertSame('<img src="data:image/webp;base64,Ynl0ZXM=" width="10" height="10">', $result);
    }

    #[Test]
    public function previewUriPrefixesTheBase64EncodedPreview(): void
    {
        self::assertSame('data:image/webp;base64,Ynl0ZXM=', ProvisionalSrc::previewUri('bytes'));
    }

    #[Test]
    #[DataProvider('quotes')]
    public function previewMarkerAttributeUsesTheGivenQuoteCharacter(string $quote): void
    {
        self::assertSame(' data-file-sync-preview='.$quote.'1'.$quote, ProvisionalSrc::previewMarkerAttribute($quote));
    }

    public static function quotes(): Generator
    {
        yield 'double quote' => ['"'];
        yield 'single quote' => ["'"];
    }

    #[Test]
    public function withProvisionalQuerySuffixesTheSrcValueBetweenItsQuotes(): void
    {
        $tag = '<img src="orig.jpg" width="10" height="10">';
        $src = ['orig.jpg', 10];

        $result = ProvisionalSrc::withProvisionalQuery($tag, $src, 0);

        self::assertSame('<img src="orig.jpg?file-sync-provisional=1" width="10" height="10">', $result);
    }

    #[Test]
    #[DataProvider('urls')]
    public function suffixedWithProvisionalQueryPlacesTheSuffixBeforeAnyFragment(string $url, string $expected): void
    {
        self::assertSame($expected, ProvisionalSrc::suffixedWithProvisionalQuery($url));
    }

    public static function urls(): Generator
    {
        yield 'plain url' => ['orig.jpg', 'orig.jpg?file-sync-provisional=1'];
        yield 'url with an existing query' => ['orig.jpg?x=1', 'orig.jpg?x=1&amp;file-sync-provisional=1'];
        yield 'url with a fragment' => ['orig.jpg#frag', 'orig.jpg?file-sync-provisional=1#frag'];
        yield 'url with a query and a fragment' => ['orig.jpg?x=1#frag', 'orig.jpg?x=1&amp;file-sync-provisional=1#frag'];
    }

    #[Test]
    public function appendedAddsTheAttributeBeforeTheClosingBracket(): void
    {
        $result = ProvisionalSrc::appended('<img src="a.jpg">', ' data-file-sync="tok"');

        self::assertSame('<img src="a.jpg" data-file-sync="tok">', $result);
    }

    #[Test]
    public function appendedKeepsASelfClosingTagSelfClosing(): void
    {
        $result = ProvisionalSrc::appended('<img src="a.jpg"/>', ' data-file-sync="tok"');

        self::assertSame('<img src="a.jpg" data-file-sync="tok" />', $result);
    }

    #[Test]
    public function appendedTrimsWhitespaceBeforeTheClosingBracket(): void
    {
        $result = ProvisionalSrc::appended('<img src="a.jpg" >', ' data-file-sync="tok"');

        self::assertSame('<img src="a.jpg" data-file-sync="tok">', $result);
    }

    #[Test]
    public function declaresItsOwnSizeIsTrueWhenBothWidthAndHeightArePresent(): void
    {
        self::assertTrue(ProvisionalSrc::declaresItsOwnSize('<img src="a.jpg" width="10" height="20">'));
    }

    #[Test]
    public function declaresItsOwnSizeIsFalseWithoutWidth(): void
    {
        self::assertFalse(ProvisionalSrc::declaresItsOwnSize('<img src="a.jpg" height="20">'));
    }

    #[Test]
    public function declaresItsOwnSizeIsFalseWithoutHeight(): void
    {
        self::assertFalse(ProvisionalSrc::declaresItsOwnSize('<img src="a.jpg" width="10">'));
    }

    #[Test]
    public function declaresItsOwnSizeIgnoresADataWidthAttribute(): void
    {
        self::assertFalse(ProvisionalSrc::declaresItsOwnSize('<img src="a.jpg" data-width="10" height="20">'));
    }

    #[Test]
    public function declaresItsOwnSizeIsFalseForAnEmptyValue(): void
    {
        self::assertFalse(ProvisionalSrc::declaresItsOwnSize('<img src="a.jpg" width="" height="20">'));
    }
}
