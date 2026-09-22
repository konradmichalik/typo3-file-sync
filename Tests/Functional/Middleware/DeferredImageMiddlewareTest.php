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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Middleware;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Middleware\DeferredImageMiddleware;
use KonradMichalik\Typo3FileSync\Resource\Preview\PreviewStore;
use KonradMichalik\Typo3FileSync\Service\DeferredTokenService;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{Response, ServerRequest, Stream};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function base64_decode;
use function base64_encode;
use function pack;
use function preg_match;
use function preg_match_all;
use function str_repeat;
use function strlen;
use function substr;
use function substr_count;

/**
 * DeferredImageMiddlewareTest.
 *
 * The fixture storage is a real local storage rooted at fileadmin/, so the
 * public prefix the middleware strips off an image URL is the one FAL would
 * really have produced rather than one the test made up.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(DeferredImageMiddleware::class)]
final class DeferredImageMiddlewareTest extends FunctionalTestCase
{
    private const PROVISIONAL_TAG = '<img src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg" alt="provisional">';

    private const PROVISIONAL_URL = '/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg';

    /**
     * Only a tag stating a width and a height of its own is inlined into, so
     * every preview case that expects a data URI has to carry both.
     */
    private const SIZED_PROVISIONAL_TAG = '<img src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg" width="300" height="200" alt="provisional">';

    /**
     * Responsive markup, which states its size and is still laid out from
     * srcset rather than from src.
     */
    private const SRCSET_PROVISIONAL_TAG = '<img src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg" srcset="/fileadmin/narrow.jpg 300w, /fileadmin/wide.jpg 600w" width="300" height="200" alt="responsive">';

    private const SECOND_PROVISIONAL_URL = '/fileadmin/_processed_/a/b/csm_provisional_ccc.jpg';

    private const SECOND_SIZED_PROVISIONAL_TAG = '<img src="/fileadmin/_processed_/a/b/csm_provisional_ccc.jpg" width="150" height="100" alt="second">';

    private const REAL_TAG = '<img src="/fileadmin/_processed_/a/b/csm_real_bbb.jpg" alt="real">';

    /**
     * The rendition the fixture's provisional image resolves to, which is the
     * pair a preview is stored under.
     */
    private const PREVIEW_IDENTIFIER = '/_processed_/a/b/csm_provisional_aaa.jpg';

    private const SECOND_PREVIEW_IDENTIFIER = '/_processed_/a/b/csm_provisional_ccc.jpg';

    private const PREVIEW_STORAGE = 9;

    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    /**
     * The functional test default turns the hash cache into a null backend,
     * which would silently retire the layer this middleware leans on hardest.
     *
     * @var array<string, mixed>
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['hash' => ['backend' => Typo3DatabaseBackend::class]]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = true;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_PREVIEW_IMAGES] = false;
        $this->get(CacheManager::class)->getCache('runtime')->flush();
        $this->get(CacheManager::class)->getCache('hash')->flush();
    }

    protected function tearDown(): void
    {
        // The store lives on the filesystem, which no database rollback
        // reaches, so a preview one case wrote would still be there for the
        // next one.
        $store = new PreviewStore();
        $store->remove(self::PREVIEW_STORAGE, self::PREVIEW_IDENTIFIER);
        $store->remove(self::PREVIEW_STORAGE, self::SECOND_PREVIEW_IDENTIFIER);
        parent::tearDown();
    }

    #[Test]
    public function leavesTheBodyUntouchedWhileTheFeatureToggleIsOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = false;
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $body = $this->page(self::PROVISIONAL_TAG);

        self::assertSame($body, $this->processBody($body));
    }

    #[Test]
    public function leavesTheBodyUntouchedForANonHtmlResponse(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $body = $this->page(self::PROVISIONAL_TAG);

        self::assertSame($body, $this->processBody($body, 'application/json; charset=utf-8'));
    }

    #[Test]
    public function leavesTheBodyUntouchedForANonSuccessfulResponse(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $body = $this->page(self::PROVISIONAL_TAG);

        self::assertSame($body, $this->processBody($body, 'text/html; charset=utf-8', 404));
    }

    #[Test]
    public function leavesTheBodyUntouchedWhenNothingIsProvisional(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/nothing_provisional.csv');
        $body = $this->page(self::PROVISIONAL_TAG);

        self::assertSame($body, $this->processBody($body));
    }

    /**
     * The cached count is the only thing standing between a settled
     * installation and a body scan on every single response, so it has to
     * win even when the database says otherwise.
     */
    #[Test]
    public function leavesTheBodyUntouchedWhileTheCachedProvisionalCountIsZero(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->get(CacheManager::class)->getCache('hash')
            ->set('fileSyncProvisionalCount', ['storages' => [9], 'count' => 0]);
        $body = $this->page(self::PROVISIONAL_TAG);

        self::assertSame($body, $this->processBody($body));
    }

    #[Test]
    public function marksAnImageOnAProvisionalProcessedFile(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page(self::PROVISIONAL_TAG));

        self::assertSame(110, $this->tokenOf($result));
    }

    #[Test]
    public function marksAnImageWhoseUrlCarriesNoLeadingSlashAndACacheBuster(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $tag = '<img src="fileadmin/_processed_/a/b/csm_provisional_aaa.jpg?v=17" alt="provisional">';

        $result = $this->processBody($this->page($tag));

        self::assertSame(110, $this->tokenOf($result));
    }

    #[Test]
    public function leavesAnImageOnANonProvisionalFileAlone(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page(self::PROVISIONAL_TAG.self::REAL_TAG));

        self::assertStringContainsString(self::REAL_TAG, $result);
        self::assertStringNotContainsString(self::PROVISIONAL_TAG, $result);
    }

    #[Test]
    public function leavesATagThatAlreadyCarriesTheAttributeAlone(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $tag = '<img src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg" data-file-sync="stale">';

        $result = $this->processBody($this->page($tag));

        self::assertStringContainsString($tag, $result);
        self::assertSame(1, substr_count($result, 'data-file-sync'));
    }

    #[Test]
    public function doesNotRewriteATagWhoseTrailingAttributeHidesAGreaterThanSign(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        // The pattern stops at the first ">", so this tag is only matched
        // up to the one inside alt. Rewriting that match would produce
        // markup that is no longer an img tag at all.
        $tag = '<img src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg" alt="a > b">';

        $result = $this->processBody($this->page($tag));

        self::assertStringContainsString($tag, $result);
        self::assertStringNotContainsString('data-file-sync', $result);
    }

    /**
     * The tag inside an inline script is written with single quotes so the
     * surrounding JavaScript string literal can use double ones. Injecting
     * a double quote there ends the literal and takes the whole script
     * block down with a SyntaxError.
     */
    #[Test]
    public function leavesAnImageInsideAnInlineScriptAlone(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $markup = '<script>var h = "'."<img src='/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg'>".'";</script>';

        $result = $this->processBody($this->page($markup));

        self::assertStringContainsString($markup, $result);
        self::assertStringNotContainsString('data-file-sync', $result);
    }

    #[Test]
    public function leavesAnImageInsideATextareaAlone(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $markup = '<textarea name="t">'.self::PROVISIONAL_TAG.'</textarea>';

        $result = $this->processBody($this->page($markup));

        self::assertStringContainsString($markup, $result);
        self::assertStringNotContainsString('data-file-sync', $result);
    }

    #[Test]
    public function leavesAnImageInsideAnHtmlCommentAlone(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $markup = '<!-- '.self::PROVISIONAL_TAG.' -->';

        $result = $this->processBody($this->page($markup));

        self::assertStringContainsString($markup, $result);
        self::assertStringNotContainsString('data-file-sync', $result);
    }

    /**
     * Nothing about the token needs escaping, so mirroring costs nothing
     * and keeps a single-quoted tag safe wherever it was embedded.
     */
    #[Test]
    public function mirrorsTheQuoteCharacterTheTagAlreadyUses(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $tag = "<img src='/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg'>";

        $result = $this->processBody($this->page($tag));

        self::assertMatchesRegularExpression("/data-file-sync='[^']+'/", $result);
        self::assertStringNotContainsString('data-file-sync="', $result);
    }

    #[Test]
    public function marksASelfClosingTagWithoutSwallowingItsSlash(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $tag = '<img src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg" alt="provisional" />';

        $result = $this->processBody($this->page($tag));

        self::assertSame(110, $this->tokenOf($result));
        self::assertStringContainsString('" />', $result);
        self::assertStringNotContainsString('/ data-file-sync', $result);
    }

    /**
     * A word boundary also sits inside "data-src", and greedy backtracking
     * makes the rightmost src= win, so both attribute orderings have to be
     * pinned: the real src is the one the browser renders either way.
     *
     * @return array<string, list<string>>
     */
    public static function lazyLoadingAttributeOrderProvider(): array
    {
        return [
            'placeholder src first' => [
                '<img src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg" data-src="/fileadmin/_processed_/a/b/csm_real_bbb.jpg">',
            ],
            'data-src first' => [
                '<img data-src="/fileadmin/_processed_/a/b/csm_real_bbb.jpg" src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg">',
            ],
        ];
    }

    #[Test]
    #[DataProvider('lazyLoadingAttributeOrderProvider')]
    public function readsTheRealSrcRatherThanALazyLoadingAttribute(string $tag): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page($tag));

        self::assertSame(110, $this->tokenOf($result));
    }

    #[Test]
    public function leavesATagAloneWhoseOnlyProvisionalUrlIsALazyLoadingAttribute(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $tag = '<img src="/fileadmin/_processed_/a/b/csm_real_bbb.jpg" data-src="/fileadmin/_processed_/a/b/csm_provisional_aaa.jpg">';

        $result = $this->processBody($this->page($tag));

        self::assertStringContainsString($tag, $result);
        self::assertStringNotContainsString('data-file-sync', $result);
    }

    #[Test]
    public function injectsTheScriptExactlyOnce(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page(str_repeat(self::PROVISIONAL_TAG, 10)));

        self::assertSame(10, substr_count($result, 'data-file-sync='));
        self::assertSame(1, substr_count($result, 'file-sync.js'));
        self::assertSame(1, substr_count($result, '<script type="module"'));
    }

    /**
     * The module has no way of working the endpoint out for itself: it is
     * served from _assets/ or from typo3conf/ext/ depending on the
     * installation, so its own URL says nothing about where the site root
     * is. The subdirectory case is asserted in MaterializationServiceTest,
     * which pins the site path.
     */
    #[Test]
    public function pointsTheInjectedModuleAtTheMaterializeEndpoint(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page(self::PROVISIONAL_TAG));

        self::assertSame(1, preg_match('/data-file-sync-endpoint="([^"]+)"/', $result, $matches));
        self::assertStringStartsWith('/', $matches[1]);
        self::assertStringEndsWith('/tx-file-sync/materialize', $matches[1]);
    }

    /**
     * An inline style block would be dropped by the CSP header that
     * csp-headers has already emitted further in, and the module guards
     * prefers-reduced-motion itself, so nothing but the script is injected.
     */
    #[Test]
    public function injectsNoInlineStyle(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page(self::PROVISIONAL_TAG));

        self::assertStringNotContainsString('<style', $result);
    }

    #[Test]
    public function refreshesTheContentLengthHeaderItJustInvalidated(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $body = $this->page(self::PROVISIONAL_TAG);

        $response = $this->dispatch($body, 'text/html; charset=utf-8', 200, (string) strlen($body));

        self::assertSame((string) strlen((string) $response->getBody()), $response->getHeaderLine('Content-Length'));
        self::assertGreaterThan(strlen($body), strlen((string) $response->getBody()));
    }

    #[Test]
    public function leavesTheContentLengthHeaderAbsentWhenTheResponseCarriedNone(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $response = $this->dispatch($this->page(self::PROVISIONAL_TAG));

        self::assertFalse($response->hasHeader('Content-Length'));
    }

    #[Test]
    public function persistsTheProvisionalStateAcrossRequests(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $this->processBody($this->page(self::PROVISIONAL_TAG));

        self::assertSame(
            ['storages' => [9], 'count' => 1],
            $this->get(CacheManager::class)->getCache('hash')->get('fileSyncProvisionalCount'),
        );
    }

    #[Test]
    public function injectsTheSnippetBeforeTheClosingBodyTag(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page(self::PROVISIONAL_TAG));

        self::assertStringEndsWith('</script></body></html>', $result);
    }

    #[Test]
    public function inlinesAStoredPreviewAsADataUri(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $stored = $this->storePreview('preview-bytes');

        $result = $this->processBody($this->page(self::SIZED_PROVISIONAL_TAG));

        self::assertStringContainsString('src="data:image/webp;base64,'.base64_encode($stored).'"', $result);
        // The point of inlining: the grey placeholder file is never requested.
        self::assertStringNotContainsString(self::PROVISIONAL_URL, $result);
        self::assertStringNotContainsString('data-file-sync-preview', $result);
        self::assertSame(110, $this->tokenOf($result));
    }

    #[Test]
    public function marksAnImageWithoutAStoredPreviewForThePreviewStage(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();

        $result = $this->processBody($this->page(self::SIZED_PROVISIONAL_TAG));

        self::assertStringContainsString('src="'.self::PROVISIONAL_URL.'"', $result);
        self::assertStringContainsString('data-file-sync-preview="1"', $result);
        self::assertStringNotContainsString('data:image/webp', $result);
        self::assertSame(110, $this->tokenOf($result));
    }

    /**
     * The stored preview is written on purpose: without it this case would
     * pass for a body that was never rewritten at all, which is why the token
     * is asserted too. The tag states its size for the same reason, so the
     * toggle is the only thing keeping the data URI out.
     */
    #[Test]
    public function inlinesNothingWhileThePreviewToggleIsOff(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->storePreview('preview-bytes');

        $result = $this->processBody($this->page(self::SIZED_PROVISIONAL_TAG));

        self::assertStringContainsString('src="'.self::PROVISIONAL_URL.'"', $result);
        self::assertStringNotContainsString('data:image/webp', $result);
        self::assertStringNotContainsString('data-file-sync-preview', $result);
        self::assertSame(110, $this->tokenOf($result));
    }

    /**
     * Stored bytes are arbitrary binary. Base64 is the only encoding applied
     * to them, so what the browser decodes is byte for byte what the store
     * holds and nothing in between can end the attribute early.
     */
    #[Test]
    public function inlinesTheExactBytesTheStoreHoldsWithoutEscapingThem(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $bytes = $this->storePreview("\x00\xff<>&\"'\x1a webp-ish");

        $result = $this->processBody($this->page(self::SIZED_PROVISIONAL_TAG));

        self::assertSame(1, preg_match('/<img[^>]*\ssrc="([^"]+)"/', $result, $matches));
        self::assertSame('data:image/webp;base64,'.base64_encode($bytes), $matches[1]);
        self::assertSame($bytes, base64_decode(substr($matches[1], strlen('data:image/webp;base64,')), true));
    }

    /**
     * Inlining writes no quote of its own: it replaces the src value between
     * the two the tag already carries, so a single-quoted tag stays
     * single-quoted structurally rather than by mirroring. What this pins is
     * the other half of that, namely that the data URI itself contains no
     * quote character that would close the attribute early.
     */
    #[Test]
    public function keepsASingleQuotedTagSingleQuotedWhenInlining(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $stored = $this->storePreview('preview-bytes');

        $result = $this->processBody($this->page("<img src='".self::PROVISIONAL_URL."' width='300' height='200'>"));

        self::assertStringContainsString("src='data:image/webp;base64,".base64_encode($stored)."'", $result);
        self::assertStringNotContainsString('src="data:', $result);
    }

    #[Test]
    public function mirrorsTheQuoteCharacterWhenMarkingForThePreviewStage(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();

        $result = $this->processBody($this->page("<img src='".self::PROVISIONAL_URL."' width='300' height='200'>"));

        self::assertStringContainsString("data-file-sync-preview='1'", $result);
        self::assertStringNotContainsString('data-file-sync-preview="', $result);
    }

    /**
     * Inlining must not become a second way past the spans the base branch
     * refuses to touch: an injected data URI inside a script would end the
     * JavaScript string literal the tag sits in just as an attribute would.
     */
    #[Test]
    public function leavesAnImageInsideAnInlineScriptAloneEvenWithAStoredPreview(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $this->storePreview('preview-bytes');
        $markup = '<script>var h = "'."<img src='".self::PROVISIONAL_URL."' width='300' height='200'>".'";</script>';

        $result = $this->processBody($this->page($markup));

        self::assertStringContainsString($markup, $result);
        self::assertStringNotContainsString('data:image/webp', $result);
    }

    /**
     * Each entry states less than a width and a height together, whether by
     * leaving one out, by emptying one, or by spelling them as data
     * attributes the browser lays nothing out from.
     *
     * @return array<string, list<string>>
     */
    public static function tagWithoutItsOwnSizeProvider(): array
    {
        return [
            'no dimensions at all' => [self::PROVISIONAL_TAG],
            'width only' => ['<img src="'.self::PROVISIONAL_URL.'" width="300">'],
            'height only' => ['<img src="'.self::PROVISIONAL_URL.'" height="200">'],
            'empty height' => ['<img src="'.self::PROVISIONAL_URL.'" width="300" height="">'],
            'data attributes only' => ['<img src="'.self::PROVISIONAL_URL.'" data-width="300" data-height="200">'],
        ];
    }

    /**
     * The stored preview is 32 pixels on its longest edge and the grey
     * placeholder is the rendition's full size, so a preview reaching a tag
     * that states no size of its own would shrink it until the original
     * lands. That is true of the data URI the module assigns just as much as
     * of an inlined one, so such a tag is kept out of the stage entirely and
     * carries no marker for the module to find.
     *
     * A preview is stored here on purpose: what is pinned is the guard, not
     * an absent preview, so the case fails the moment the guard is dropped
     * and the data URI arrives after all.
     */
    #[Test]
    #[DataProvider('tagWithoutItsOwnSizeProvider')]
    public function keepsATagStatingNoSizeOfItsOwnOutOfThePreviewStage(string $tag): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $this->storePreview('preview-bytes');

        $result = $this->processBody($this->page($tag));

        self::assertStringContainsString('src="'.self::PROVISIONAL_URL.'"', $result);
        self::assertStringNotContainsString('data-file-sync-preview', $result);
        self::assertStringNotContainsString('data:image/webp', $result);
        self::assertSame(110, $this->tokenOf($result));
    }

    /**
     * A responsive tag states its size and would pass the size gate, but the
     * browser picks its image from srcset and never reads src. Marking it
     * buys a source rendition downloaded and a preview stored for something
     * no visitor ever sees, and inlining writes a data URI into the HTML
     * that nothing renders.
     *
     * The preview is stored on purpose, so the case fails the moment the
     * guard is dropped rather than for want of a preview.
     */
    #[Test]
    public function keepsATagPickingItsImageFromSrcsetOutOfThePreviewStage(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $this->storePreview('preview-bytes');

        $result = $this->processBody($this->page(self::SRCSET_PROVISIONAL_TAG));

        self::assertStringContainsString('src="'.self::PROVISIONAL_URL.'"', $result);
        self::assertStringNotContainsString('data-file-sync-preview', $result);
        self::assertStringNotContainsString('data:image/webp', $result);
        // The original stage is untouched: the real file still replaces the
        // placeholder, which is all a responsive tag ever got.
        self::assertSame(110, $this->tokenOf($result));
    }

    /**
     * data-srcset is a lazy-loading attribute the browser lays nothing out
     * from, so a tag carrying only that still renders from its src and still
     * deserves its preview. Without the lookbehind the guard would read the
     * "srcset" inside it and decline the whole category.
     */
    #[Test]
    public function stillPreviewsATagWhoseSrcsetIsOnlyADataAttribute(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $stored = $this->storePreview('preview-bytes');

        $result = $this->processBody($this->page(
            '<img src="'.self::PROVISIONAL_URL.'" data-srcset="/fileadmin/wide.jpg 600w" width="300" height="200">',
        ));

        self::assertStringContainsString('src="data:image/webp;base64,'.base64_encode($stored).'"', $result);
    }

    /**
     * The offsets preg_replace_callback reports are offsets into the whole
     * body, and the second image is rewritten after the first replacement has
     * already changed that body's length. Every other preview case holds a
     * single image, so nothing else would notice the day that subtraction
     * starts drifting. The two previews hold different bytes of different
     * lengths, so asserting one payload twice cannot pass.
     */
    #[Test]
    public function inlinesEachOfTwoImagesInOneBodyWithItsOwnStoredPreview(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $first = $this->storePreview('first-image-preview-bytes');
        $second = self::webp('second');
        (new PreviewStore())->write(self::PREVIEW_STORAGE, self::SECOND_PREVIEW_IDENTIFIER, $second);

        $result = $this->processBody(
            $this->page(self::SIZED_PROVISIONAL_TAG.self::SECOND_SIZED_PROVISIONAL_TAG),
        );

        self::assertSame(2, preg_match_all('/<img[^>]*\ssrc="([^"]+)"/', $result, $matches));
        self::assertSame(
            [
                'data:image/webp;base64,'.base64_encode($first),
                'data:image/webp;base64,'.base64_encode($second),
            ],
            $matches[1],
        );
        self::assertStringNotContainsString(self::PROVISIONAL_URL, $result);
        self::assertStringNotContainsString(self::SECOND_PROVISIONAL_URL, $result);
    }

    /**
     * The store is read once per rendition and the answer reused for every
     * further tag pointing at it, which is a branch no other case reaches:
     * every existing body carries each rendition at most once.
     */
    #[Test]
    public function inlinesTheSameRenditionIntoEveryTagThatRepeatsIt(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');
        $this->enablePreviews();
        $stored = $this->storePreview('repeated-preview-bytes');

        $result = $this->processBody(
            $this->page(self::SIZED_PROVISIONAL_TAG.self::SIZED_PROVISIONAL_TAG),
        );

        $expected = 'data:image/webp;base64,'.base64_encode($stored);
        self::assertSame(2, preg_match_all('/<img[^>]*\ssrc="([^"]+)"/', $result, $matches));
        self::assertSame([$expected, $expected], $matches[1]);
        self::assertStringNotContainsString(self::PROVISIONAL_URL, $result);
    }

    private function enablePreviews(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_PREVIEW_IMAGES] = true;
    }

    /**
     * @return string the bytes the store now holds, which the middleware has
     *                to inline unchanged
     */
    private function storePreview(string $payload): string
    {
        $webp = self::webp($payload);
        (new PreviewStore())->write(self::PREVIEW_STORAGE, self::PREVIEW_IDENTIFIER, $webp);

        return $webp;
    }

    /**
     * A RIFF container around the payload, with the length field a real
     * encoder would write. The store hands back nothing else, since it reads
     * a short-written file as absent.
     */
    private static function webp(string $payload): string
    {
        return 'RIFF'.pack('V', 4 + strlen($payload)).'WEBP'.$payload;
    }

    private function page(string $markup): string
    {
        return '<!DOCTYPE html><html><head><title>t</title></head><body>'.$markup.'</body></html>';
    }

    private function processBody(string $body, string $contentType = 'text/html; charset=utf-8', int $status = 200): string
    {
        return (string) $this->dispatch($body, $contentType, $status)->getBody();
    }

    private function dispatch(
        string $body,
        string $contentType = 'text/html; charset=utf-8',
        int $status = 200,
        ?string $contentLength = null,
    ): ResponseInterface {
        $request = (new ServerRequest('https://example.com/a-page', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);

        return $this->get(DeferredImageMiddleware::class)
            ->process($request, $this->handlerReturning($body, $contentType, $status, $contentLength));
    }

    /**
     * Reads the single data-file-sync value out of the result and turns it
     * back into a processed file uid through the service that signed it, so
     * no signature is ever hardcoded here.
     */
    private function tokenOf(string $body): ?int
    {
        if (1 !== preg_match('/data-file-sync="([^"]+)"/', $body, $matches)) {
            self::fail('No data-file-sync attribute was injected.');
        }

        return $this->get(DeferredTokenService::class)->resolve($matches[1]);
    }

    private function handlerReturning(string $body, string $contentType, int $status, ?string $contentLength): RequestHandlerInterface
    {
        $stream = new Stream('php://temp', 'r+');
        $stream->write($body);
        $stream->rewind();

        $response = (new Response($stream, $status))->withHeader('Content-Type', $contentType);
        if (null !== $contentLength) {
            $response = $response->withHeader('Content-Length', $contentLength);
        }

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }
}
