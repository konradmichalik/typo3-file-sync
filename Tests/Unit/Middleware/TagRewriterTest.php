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

use KonradMichalik\Ttt\Attribute\{WithEnvironment, WithTypo3ConfVars};
use KonradMichalik\Typo3FileSync\Middleware\TagRewriter;
use KonradMichalik\Typo3FileSync\Resource\Preview\PreviewStore;
use KonradMichalik\Typo3FileSync\Service\DeferredTokenService;
use KonradMichalik\Typo3FileSync\Tests\StoredPreview;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Crypto\HashService;

use function preg_match;

/**
 * TagRewriterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(TagRewriter::class)]
#[WithEnvironment]
#[WithTypo3ConfVars(['SYS' => ['folderCreateMask' => '2775']])]
final class TagRewriterTest extends TestCase
{
    use StoredPreview;

    private DeferredTokenService $deferredTokenService;

    private PreviewStore $previewStore;

    private TagRewriter $subject;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $this->deferredTokenService = new DeferredTokenService(new HashService());
        $this->previewStore = new PreviewStore();
        $this->subject = new TagRewriter($this->deferredTokenService, $this->previewStore);
    }

    #[Test]
    public function declinesATagThatIsAlreadyMarked(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10" data-file-sync="x">';

        $result = $this->subject->rewriteTag([[$tag, 0]], 0, ['a.jpg' => 'ident'], ['ident' => ['uid' => 1, 'storage' => 1]], false, false, []);

        self::assertNull($result);
    }

    #[Test]
    public function declinesATagWithUnbalancedQuotes(): void
    {
        $tag = '<img src="a.jpg" alt="broken>';

        $result = $this->subject->rewriteTag([[$tag, 0]], 0, [], [], false, false, []);

        self::assertNull($result);
    }

    #[Test]
    public function declinesATagWhoseSrcsetCannotBeParsed(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10" srcset=unquoted>';

        $result = $this->subject->rewriteTag($this->imgMatch($tag), 0, ['a.jpg' => 'ident'], ['ident' => ['uid' => 1, 'storage' => 1]], false, false, []);

        self::assertNull($result);
    }

    #[Test]
    public function returnsNullWhenNeitherSrcNorSrcsetIsProvisional(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10">';

        $result = $this->subject->rewriteTag($this->imgMatch($tag), 0, [], [], false, false, []);

        self::assertNull($result);
    }

    #[Test]
    public function suffixesAProvisionalSrcWithAQueryWhenPreviewsAreDisabled(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10">';

        [$rewritten, $previewByIdentifier] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['a.jpg' => 'ident'],
            ['ident' => ['uid' => 5, 'storage' => 1]],
            false,
            false,
            [],
        );

        $token = $this->deferredTokenService->create(5);
        self::assertSame('<img src="a.jpg?file-sync-provisional=1" width="10" height="10" data-file-sync="'.$token.'">', $rewritten);
        self::assertSame([], $previewByIdentifier);
    }

    #[Test]
    public function marksAnEligibleTagForAPreviewWhenNoneIsStoredYet(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10">';

        [$rewritten, $previewByIdentifier] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['a.jpg' => 'ident'],
            ['ident' => ['uid' => 5, 'storage' => 1]],
            true,
            false,
            [],
        );

        $token = $this->deferredTokenService->create(5);
        self::assertSame(
            '<img src="a.jpg?file-sync-provisional=1" width="10" height="10" data-file-sync-preview="1" data-file-sync="'.$token.'">',
            $rewritten,
        );
        self::assertSame(['ident' => null], $previewByIdentifier);
    }

    #[Test]
    public function inlinesAStoredPreviewAndSkipsTheMarkerAttribute(): void
    {
        $this->previewStore->write(1, 'ident', self::webp('x'));
        $tag = '<img src="a.jpg" width="10" height="10">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['a.jpg' => 'ident'],
            ['ident' => ['uid' => 5, 'storage' => 1]],
            true,
            false,
            [],
        );

        $token = $this->deferredTokenService->create(5);
        $previewUri = 'data:image/webp;base64,'.base64_encode(self::webp('x'));
        self::assertSame('<img src="'.$previewUri.'" width="10" height="10" data-file-sync="'.$token.'">', $rewritten);
    }

    #[Test]
    public function anUnsizedTagIsIneligibleForAPreview(): void
    {
        $tag = '<img src="a.jpg">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['a.jpg' => 'ident'],
            ['ident' => ['uid' => 5, 'storage' => 1]],
            true,
            false,
            [],
        );

        $token = $this->deferredTokenService->create(5);
        self::assertSame('<img src="a.jpg?file-sync-provisional=1" data-file-sync="'.$token.'">', $rewritten);
    }

    #[Test]
    public function beingInsideAPictureMakesASizedTagIneligibleForAPreview(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['a.jpg' => 'ident'],
            ['ident' => ['uid' => 5, 'storage' => 1]],
            true,
            true,
            [],
        );

        $token = $this->deferredTokenService->create(5);
        self::assertSame('<img src="a.jpg?file-sync-provisional=1" width="10" height="10" data-file-sync="'.$token.'">', $rewritten);
    }

    #[Test]
    public function aNonProvisionalSrcIsLeftUntouchedWhileItsProvisionalSrcsetIsSuffixed(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10" srcset="b.jpg 1x, c.jpg 2x">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['b.jpg' => 'identB'],
            ['identB' => ['uid' => 7, 'storage' => 1]],
            false,
            false,
            [],
        );

        $token = $this->deferredTokenService->create(7);
        self::assertSame(
            '<img src="a.jpg" width="10" height="10" srcset="b.jpg?file-sync-provisional=1 1x, c.jpg 2x" data-file-sync="srcset" data-file-sync-srcset="'.$token.' 1x, c.jpg 2x">',
            $rewritten,
        );
    }

    #[Test]
    public function aPictureSourcesProvisionalSrcsetIsSuffixed(): void
    {
        $tag = '<source srcset="b.jpg 1x, c.jpg 2x">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->sourceMatch($tag),
            0,
            ['b.jpg' => 'identB'],
            ['identB' => ['uid' => 9, 'storage' => 2]],
            false,
            true,
            [],
        );

        $token = $this->deferredTokenService->create(9);
        self::assertSame(
            '<source srcset="b.jpg?file-sync-provisional=1 1x, c.jpg 2x" data-file-sync="srcset" data-file-sync-srcset="'.$token.' 1x, c.jpg 2x">',
            $rewritten,
        );
    }

    #[Test]
    public function aPreviousLookupThatFoundNothingIsNotRepeated(): void
    {
        $this->previewStore->write(1, 'ident', self::webp('x'));
        $tag = '<img src="a.jpg" width="10" height="10">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['a.jpg' => 'ident'],
            ['ident' => ['uid' => 5, 'storage' => 1]],
            true,
            false,
            ['ident' => null],
        );

        $token = $this->deferredTokenService->create(5);
        self::assertSame(
            '<img src="a.jpg?file-sync-provisional=1" width="10" height="10" data-file-sync-preview="1" data-file-sync="'.$token.'">',
            $rewritten,
        );
    }

    #[Test]
    public function anEligibleSrcsetProvisionalPreviewFallsBackToTheQuerySuffixWhenNoneIsStoredYet(): void
    {
        $tag = '<img src="a.jpg" width="10" height="10" srcset="b.jpg 1x, c.jpg 2x">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['b.jpg' => 'identB'],
            ['identB' => ['uid' => 11, 'storage' => 1]],
            true,
            false,
            [],
        );

        $token = $this->deferredTokenService->create(11);
        self::assertSame(
            '<img src="a.jpg" width="10" height="10" srcset="b.jpg?file-sync-provisional=1 1x, c.jpg 2x" data-file-sync-preview="1" data-file-sync="srcset" data-file-sync-srcset="'.$token.' 1x, c.jpg 2x">',
            $rewritten,
        );
    }

    #[Test]
    public function aStoredPreviewFoundThroughSrcsetCollapsesSrcsetToThePreviewUri(): void
    {
        $this->previewStore->write(1, 'identB', self::webp('x'));
        $tag = '<img src="a.jpg" width="10" height="10" srcset="b.jpg 1x, c.jpg 2x">';

        [$rewritten] = $this->subject->rewriteTag(
            $this->imgMatch($tag),
            0,
            ['b.jpg' => 'identB'],
            ['identB' => ['uid' => 11, 'storage' => 1]],
            true,
            false,
            [],
        );

        $token = $this->deferredTokenService->create(11);
        $previewUri = 'data:image/webp;base64,'.base64_encode(self::webp('x'));
        self::assertSame(
            '<img src="a.jpg" width="10" height="10" srcset="'.$previewUri.'" data-file-sync="srcset" data-file-sync-srcset="'.$token.' 1x, c.jpg 2x">',
            $rewritten,
        );
    }

    /**
     * @return array{0: array{string, int}, 1: array{string, int}, 2: array{string, int}}
     */
    private function imgMatch(string $tag): array
    {
        if (1 !== preg_match('/(?<![-\w])src=(["\'])([^"\']+)\1/', $tag, $matches, \PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Fixture tag has no src attribute: '.$tag);
        }

        return [[$tag, 0], $matches[1], $matches[2]];
    }

    /**
     * @return array{0: array{string, int}, 1: array{string, int}, 2: array{string, int}, 3: array{string, int}}
     */
    private function sourceMatch(string $tag): array
    {
        if (1 !== preg_match('/(?<![-\w])srcset=(["\'])/', $tag, $matches, \PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Fixture tag has no srcset attribute: '.$tag);
        }

        return [[$tag, 0], ['', -1], ['', -1], $matches[1]];
    }
}
