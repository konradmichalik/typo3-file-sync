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

use KonradMichalik\Typo3FileSync\Middleware\SrcsetMarking;
use KonradMichalik\Typo3FileSync\Service\DeferredTokenService;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\HashService;

use function array_map;
use function implode;
use function range;

/**
 * SrcsetMarkingTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(SrcsetMarking::class)]
final class SrcsetMarkingTest extends TestCase
{
    private DeferredTokenService $deferredTokenService;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $this->deferredTokenService = new DeferredTokenService(new HashService());
    }

    #[Test]
    public function urlsInReturnsNothingForATagWithoutASrcset(): void
    {
        self::assertSame([], SrcsetMarking::urlsIn(['<img src="a.jpg">']));
    }

    #[Test]
    public function urlsInReturnsNothingForASrcsetItDeclines(): void
    {
        self::assertSame([], SrcsetMarking::urlsIn(['<img srcset="a.jpg 1x, b.jpg">']));
    }

    #[Test]
    public function urlsInReturnsNothingForASrcsetPastTheBatchLimit(): void
    {
        self::assertSame([], SrcsetMarking::urlsIn([$this->tagWithCandidateCount(51)]));
    }

    #[Test]
    public function urlsInMergesCandidatesAcrossTags(): void
    {
        $tags = [
            '<img srcset="a.jpg 1x, b.jpg 2x">',
            '<img srcset="c.jpg 1x">',
        ];

        self::assertSame(['a.jpg', 'b.jpg', 'c.jpg'], SrcsetMarking::urlsIn($tags));
    }

    #[Test]
    public function resolveIsNullWhenTheTagCarriesNoSrcsetAttribute(): void
    {
        self::assertNull(SrcsetMarking::resolve('<img src="a.jpg">', [], [], $this->deferredTokenService));
    }

    #[Test]
    public function resolveIsFalseWhenTheAttributeIsPresentButUnquoted(): void
    {
        self::assertFalse(SrcsetMarking::resolve('<img srcset=a.jpg>', [], [], $this->deferredTokenService));
    }

    #[Test]
    public function resolveIsFalseWhenTheValueDoesNotParse(): void
    {
        self::assertFalse(SrcsetMarking::resolve('<img srcset="a.jpg 1x, b.jpg">', [], [], $this->deferredTokenService));
    }

    #[Test]
    public function resolveIsFalsePastTheBatchLimit(): void
    {
        self::assertFalse(SrcsetMarking::resolve($this->tagWithCandidateCount(51), [], [], $this->deferredTokenService));
    }

    #[Test]
    public function resolveHasNoProvisionalCandidateWhenNoneResolves(): void
    {
        $subject = SrcsetMarking::resolve('<img srcset="a.jpg 1x, b.jpg 2x">', [], [], $this->deferredTokenService);

        self::assertInstanceOf(SrcsetMarking::class, $subject);
        self::assertFalse($subject->hasProvisional);
        self::assertNull($subject->firstProvisional);
    }

    #[Test]
    public function resolveTracksTheFirstProvisionalCandidate(): void
    {
        $rendition = ['uid' => 5, 'storage' => 1];
        $subject = SrcsetMarking::resolve(
            '<img srcset="a.jpg 1x, b.jpg 2x">',
            ['b.jpg' => 'ident-b'],
            ['ident-b' => $rendition],
            $this->deferredTokenService,
        );

        self::assertInstanceOf(SrcsetMarking::class, $subject);
        self::assertTrue($subject->hasProvisional);
        self::assertSame(['identifier' => 'ident-b', 'rendition' => $rendition], $subject->firstProvisional);
    }

    #[Test]
    public function attributeValueSubstitutesTokensForProvisionalCandidatesOnly(): void
    {
        $rendition = ['uid' => 5, 'storage' => 1];
        $subject = SrcsetMarking::resolve(
            '<img srcset="a.jpg 1x, b.jpg 2x">',
            ['b.jpg' => 'ident-b'],
            ['ident-b' => $rendition],
            $this->deferredTokenService,
        );
        self::assertInstanceOf(SrcsetMarking::class, $subject);

        $token = $this->deferredTokenService->create(5);

        self::assertSame('a.jpg 1x, '.$token.' 2x', $subject->attributeValue());
    }

    #[Test]
    public function appliedToSuffixesOnlyProvisionalCandidateUrls(): void
    {
        $rendition = ['uid' => 5, 'storage' => 1];
        $subject = SrcsetMarking::resolve(
            '<img srcset="a.jpg 1x, b.jpg 2x">',
            ['b.jpg' => 'ident-b'],
            ['ident-b' => $rendition],
            $this->deferredTokenService,
        );
        self::assertInstanceOf(SrcsetMarking::class, $subject);

        $result = $subject->appliedTo('<img srcset="a.jpg 1x, b.jpg 2x">', static fn (string $url): string => $url.'?x=1');

        self::assertSame('<img srcset="a.jpg 1x, b.jpg?x=1 2x">', $result);
    }

    #[Test]
    public function appliedToWithPreviewCollapsesTheWholeCandidateListToThePreviewUri(): void
    {
        $subject = SrcsetMarking::resolve('<img srcset="a.jpg 1x, b.jpg 2x">', [], [], $this->deferredTokenService);
        self::assertInstanceOf(SrcsetMarking::class, $subject);

        $result = $subject->appliedToWithPreview('<img srcset="a.jpg 1x, b.jpg 2x">', 'data:image/webp;base64,Zm9v');

        self::assertSame('<img srcset="data:image/webp;base64,Zm9v">', $result);
    }

    private function tagWithCandidateCount(int $count): string
    {
        $candidates = implode(', ', array_map(static fn (int $i): string => "img{$i}.jpg {$i}w", range(1, $count)));

        return '<img srcset="'.$candidates.'">';
    }
}
