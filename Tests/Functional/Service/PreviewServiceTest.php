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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Service;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Preview\{PreviewGenerator, PreviewSourceReader, PreviewStore};
use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, PreviewService};
use KonradMichalik\Typo3FileSync\Tests\Functional\RemoteInstanceHarness;
use KonradMichalik\Typo3FileSync\Tests\StoredPreview;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use Psr\Log\LoggerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_filter;
use function array_values;
use function base64_decode;
use function base64_encode;
use function explode;
use function file_get_contents;
use function sprintf;
use function time;

/**
 * PreviewServiceTest.
 *
 * Runs against a real local storage backed by the FileSyncDriver and a real
 * HTTP server: the whole point of this service is which bytes it pulls over
 * the wire and which ones it does not, and a mocked remote would assert
 * neither.
 *
 * The storage fixture has deferred loading on and the request is a frontend
 * one, which is exactly the mode in which the driver refuses to fetch. The
 * preview stage still has to fetch, so running in any other mode would test
 * a situation this service never sees.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PreviewService::class)]
#[CoversClass(PreviewSourceReader::class)]
final class PreviewServiceTest extends FunctionalTestCase
{
    use RemoteInstanceHarness;

    use StoredPreview;

    private const REQUESTED_IDENTIFIER = '/_processed_/csm_provisional.jpg';
    private const SOURCE_PATH = '/fileadmin/_processed_/csm_provisional_small.jpg';
    private const STORAGE = 9;

    /**
     * Every rendition a test in this class asks for. A stored preview outlives
     * the test instance's database, so each one has to go.
     *
     * @var list<string>
     */
    private const WRITTEN_IDENTIFIERS = [
        '/_processed_/csm_provisional.jpg',
        '/_processed_/csm_provisional_large.jpg',
        '/_processed_/csm_provisional_square.jpg',
        '/_processed_/csm_fallback.jpg',
        '/_processed_/csm_broken.png',
        '/_processed_/csm_fallback_large.jpg',
        '/_processed_/csm_onlyself.jpg',
        '/_processed_/csm_oversized.jpg',
    ];

    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!self::startServer(__DIR__.'/Fixtures/Server/preview-router.php')) {
            // Failed rather than skipped: this class is the only coverage the
            // preview stage has against a real remote, and a skip would let a
            // run go green having exercised none of it.
            self::fail('The PHP built-in server did not become reachable.');
        }
    }

    protected function setUp(): void
    {
        // The handler resolves this placeholder in its constructor, which
        // runs the first time FAL initialises the storage.
        putenv('TYPO3_FILE_SYNC_REMOTE_URL='.self::$baseUrl);

        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/materialization.csv');
        $this->importCSVDataSet(__DIR__.'/Fixtures/preview.csv');

        $this->enterFrontendRequest();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_PREVIEW_IMAGES] = true;

        // Nothing below this line reads a local file; the storage needs its
        // base path only in order to stay online.
        $this->scaffoldFileadmin();

        self::resetHitLog();
    }

    protected function tearDown(): void
    {
        $store = new PreviewStore();
        foreach (self::WRITTEN_IDENTIFIERS as $identifier) {
            $store->remove(self::STORAGE, $identifier);
            $store->remove(self::STORAGE, 'failed:'.$identifier);
        }
        $this->leaveFrontendRequest();
        $this->removeFileadmin();
        putenv('TYPO3_FILE_SYNC_REMOTE_URL');
        self::resetHitLog();
        parent::tearDown();
    }

    /**
     * A stored preview is the steady state of this feature: it is what makes
     * the second visitor of a page cost nothing. The asserted data URI is
     * built from bytes no generator would ever produce, so an implementation
     * that fetched and regenerated instead of reading the store would answer
     * with a real WebP and fail here rather than pass for the wrong reason.
     */
    #[Test]
    public function aStoredPreviewIsReturnedWithoutAnyOutboundRequest(): void
    {
        $stored = self::webp('stored-preview-bytes');
        (new PreviewStore())->write(self::STORAGE, self::REQUESTED_IDENTIFIER, $stored);
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(
            ['preview' => 'data:image/webp;base64,'.base64_encode($stored)],
            $result[$token],
        );
        self::assertSame([], self::hits(), 'A store hit must not cost a single request.');
    }

    #[Test]
    public function aMissingPreviewIsFetchedFromTheRenditionAndWrittenToTheStore(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertArrayHasKey('preview', $result[$token]);
        self::assertStringStartsWith('data:image/webp;base64,', $result[$token]['preview']);

        // Derived from the requested rendition (300x200), not from the
        // 150x100 payload that was actually downloaded.
        self::assertSame([32, 21], self::dimensionsOf($result[$token]['preview']));

        // Keyed by the rendition the token names, not by the original it was
        // built from, so the next rendition of the same picture gets a preview
        // cropped to its own shape rather than this one's.
        self::assertNotNull((new PreviewStore())->read(self::STORAGE, self::REQUESTED_IDENTIFIER));
    }

    /**
     * The smallest rendition is fetched once for the whole batch, under the
     * very path the buffer is filled with. Deriving the prefetch key from
     * anything but the driver leaves the buffer unread: the fetch then falls
     * back to a serial request and the log carries two lines instead of one.
     */
    #[Test]
    public function twoRenditionsOfOneOriginalCostASingleRequest(): void
    {
        $tokenService = $this->get(DeferredTokenService::class);
        $tokens = [$tokenService->create(10), $tokenService->create(11)];

        $result = $this->get(PreviewService::class)->preview($tokens);

        self::assertArrayHasKey('preview', $result[$tokens[0]]);
        self::assertArrayHasKey('preview', $result[$tokens[1]]);
        self::assertSame([self::SOURCE_PATH], self::hits());
    }

    /**
     * Two renditions of one picture in different shapes. They share a single
     * download, and each gets a preview cropped to its own aspect ratio: one
     * 3:2 and one square. A store keyed by the original instead would hand the
     * square slot the first one's 3:2 blur, which is a visible stretch in
     * exactly the seconds this feature exists to improve.
     */
    #[Test]
    public function eachRenditionGetsAPreviewInItsOwnShape(): void
    {
        $tokenService = $this->get(DeferredTokenService::class);
        $landscape = $tokenService->create(10);
        $square = $tokenService->create(13);

        // The square one first, so that a shared key would be overwritten by
        // the landscape crop and the re-request below would read that back.
        $result = $this->get(PreviewService::class)->preview([$square, $landscape]);

        self::assertSame([32, 21], self::dimensionsOf($result[$landscape]['preview']));
        self::assertSame([32, 32], self::dimensionsOf($result[$square]['preview']));
        self::assertSame([self::SOURCE_PATH], self::hits(), 'Both shapes share one download.');

        // Within one batch every rendition is generated from the same bytes,
        // so a shared key still produces the right crops. The stretch shows on
        // the next request, when the second rendition reads back a preview
        // that was stored for the first one's shape. That is the assertion to
        // read first when this test goes red.
        $again = $this->get(PreviewService::class)->preview([$square]);

        self::assertSame([32, 32], self::dimensionsOf($again[$square]['preview']));
        self::assertSame([self::SOURCE_PATH], self::hits(), 'The second request is a store hit.');

        $store = new PreviewStore();
        self::assertNotNull($store->read(self::STORAGE, self::REQUESTED_IDENTIFIER));
        self::assertNotNull($store->read(self::STORAGE, '/_processed_/csm_provisional_square.jpg'));
    }

    /**
     * A processed row carries an empty identifier until its file is actually
     * written. Keying a preview by the empty string would hand every such
     * rendition of the storage whichever picture got there first, so the
     * request is answered rather than stored under a colliding key.
     */
    #[Test]
    public function aRenditionWithoutAnIdentifierYieldsUnavailable(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(41);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        // Read raw rather than as a preview, so that anything at all stored
        // under the empty string shows up here.
        self::assertNull((new PreviewStore())->readMarker(self::STORAGE, ''));
        self::assertSame([], self::hits());
    }

    /**
     * A picture whose only recorded rendition is the one being waited for
     * has no cheap proxy to blur. Taking that rendition as its own source
     * downloads the very file the original stage is about to deliver, for a
     * couple of hundred bytes of blur shown in the seconds in between. Where
     * a sibling exists it was chosen as the narrowest there is, so that case
     * remains worth its fetch.
     */
    #[Test]
    public function aRenditionThatIsItsOwnOnlySourceIsNotPreviewed(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(50);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertSame([], self::hits(), 'The rendition being waited for was downloaded in order to blur itself.');
    }

    #[Test]
    public function aFileWithoutAnyRenditionYieldsUnavailable(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(40);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertSame([], self::hits());
    }

    /**
     * The guard belongs in front of the fetch rather than inside the
     * generator. A build that cannot encode WebP stores nothing, so every
     * rendition is marked again on every response and asked for again on
     * every page view, and each ask would pay a real download from the
     * remote instance for bytes discarded a line later.
     *
     * The generator is constructed rather than resolved, because a GD build
     * without the encoder cannot be reproduced inside this process.
     */
    #[Test]
    public function aBuildWithoutAWebPEncoderDownloadsNothing(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->withoutWebPSupport()->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertSame([], self::hits(), 'A source rendition was downloaded to feed an encoder that does not exist.');
        // Damping would be the wrong answer here as well: it bounds how
        // often that download happens, never that it happens at all.
        self::assertNull((new PreviewStore())->readMarker(self::STORAGE, 'failed:'.self::REQUESTED_IDENTIFIER));
    }

    #[Test]
    public function aNotFoundFromTheRemoteYieldsUnavailable(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(20);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
    }

    /**
     * A rendition that no longer exists upstream is never stored, so without a
     * negative marker it is re-fetched by every visitor of the page. The rate
     * limiter admits 60 requests a minute of 50 tokens each, which is why a
     * permanently dead rendition is the cheap fetch that adds up.
     */
    #[Test]
    public function aRenditionThatFailedIsNotAskedForAgain(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(20);
        $service = $this->get(PreviewService::class);

        $first = $service->preview([$token]);
        $afterFirst = self::hits();
        $second = $service->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $first[$token]);
        self::assertSame(['error' => 'unavailable'], $second[$token]);
        self::assertSame($afterFirst, self::hits(), 'The second attempt must not reach the remote at all.');

        // And what the first attempt cost, because it is not one request: the
        // prefetch buffers a 200 only, so a path that 404s is asked for once
        // by the pool and once again by the serial read behind it. Every
        // undamped retry is therefore worth two requests, not one.
        self::assertSame(
            ['/fileadmin/_processed_/csm_broken_small.png', '/fileadmin/_processed_/csm_broken_small.png'],
            $afterFirst,
        );
    }

    /**
     * The marker holds the second it was written in, so it expires by being
     * read. A marker older than the window must not keep a rendition that has
     * since been restored upstream from ever being tried again.
     */
    #[Test]
    public function aRenditionWhoseFailureHasExpiredIsAskedForAgain(): void
    {
        (new PreviewStore())->write(self::STORAGE, 'failed:'.self::REQUESTED_IDENTIFIER, (string) (time() - 301));
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertArrayHasKey('preview', $result[$token]);
        self::assertSame([self::SOURCE_PATH], self::hits());
        // The rendition works again, so its marker is gone rather than left to
        // be re-read for the rest of the store's life.
        self::assertNull((new PreviewStore())->readMarker(self::STORAGE, 'failed:'.self::REQUESTED_IDENTIFIER));
    }

    /**
     * The rendition asked for is the larger of the two the fixture records,
     * so its source is the sibling the remote answers with a login page
     * rather than an image. Asking for the smaller one would now be declined
     * before the request, since it is its own only source.
     */
    #[Test]
    public function aRemotePayloadThatIsNotAnImageYieldsUnavailable(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(52);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertSame(['/fileadmin/_processed_/csm_fallback.jpg'], self::hits());
        self::assertNull((new PreviewStore())->readMarker(self::STORAGE, '/_processed_/csm_fallback_large.jpg'));
    }

    /**
     * The remote decides how large a rendition in its _processed_ folder is,
     * and nothing in the local database constrains it. Reading the spooled
     * response back unbounded would put that whole body on the heap, fifty
     * times over on a full batch, on an endpoint anyone can post to.
     *
     * The peak is measured rather than the answer, because the answer is
     * 'unavailable' either way: the generator rejects an over-cap payload as
     * well, only after it has already been buffered whole.
     */
    #[Test]
    public function anOversizedSourceIsRejectedWithoutBufferingTheWholeBody(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(60);
        $service = $this->get(PreviewService::class);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $result = $service->preview([$token]);
        $held = memory_get_peak_usage() - $before;

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertSame(['/fileadmin/_processed_/csm_oversized_small.jpg'], self::hits());
        self::assertLessThan(
            8 * 1024 * 1024,
            $held,
            sprintf('The 32 MiB body reached the heap: %d bytes were held at peak.', $held),
        );
    }

    /**
     * The cap the reader applies is its own and is reached before any
     * decoder sees the bytes, so the generator's warning cannot cover it.
     * Read directly rather than through the service, because the service
     * answers 'unavailable' for a dozen reasons and only the line says which.
     */
    #[Test]
    public function anOversizedSourceReportsWhyItWasNotRead(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with(self::stringContains('fileadmin/_processed_/csm_oversized_small.jpg'));
        $reader = $this->get(PreviewSourceReader::class);
        $reader->setLogger($logger);

        $bytes = $reader->read(['token' => ['storage' => self::STORAGE, 'identifier' => '/_processed_/csm_oversized_small.jpg']]);

        self::assertNull($bytes['token']);
    }

    #[Test]
    public function anInvalidTokenYieldsInvalid(): void
    {
        $result = $this->get(PreviewService::class)->preview(['9999.deadbeef']);

        self::assertSame(['error' => 'invalid'], $result['9999.deadbeef']);
        self::assertSame([], self::hits());
    }

    /**
     * A signed token whose rendition row was meanwhile deleted resolves, so
     * only the database lookup can reject it.
     */
    #[Test]
    public function aSignedTokenForAMissingRenditionYieldsInvalid(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(9999);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'invalid'], $result[$token]);
    }

    /**
     * One unusable token must not take the batch down with it: the visitor's
     * fallback for a missing preview is the grey placeholder already on
     * screen, and every other image on the page still deserves its preview.
     */
    #[Test]
    public function oneFailingTokenLeavesTheRestOfTheBatchIntact(): void
    {
        $tokenService = $this->get(DeferredTokenService::class);
        $good = $tokenService->create(10);
        $bad = $tokenService->create(20);

        $result = $this->get(PreviewService::class)->preview([$bad, $good, '9999.deadbeef']);

        self::assertSame(['error' => 'unavailable'], $result[$bad]);
        self::assertSame(['error' => 'invalid'], $result['9999.deadbeef']);
        self::assertArrayHasKey('preview', $result[$good]);
    }

    /**
     * The service as it runs on a GD build compiled without WebP support,
     * which is the one collaborator this environment cannot provide.
     */
    private function withoutWebPSupport(): PreviewService
    {
        return new PreviewService(
            $this->get(DeferredTokenService::class),
            $this->get(FileRepository::class),
            new PreviewGenerator(false),
            $this->get(PreviewSourceReader::class),
            new PreviewStore(),
        );
    }

    /**
     * @return array{int, int}
     */
    private static function dimensionsOf(string $dataUri): array
    {
        $webp = base64_decode(explode(',', $dataUri, 2)[1], true);
        self::assertIsString($webp);
        $info = getimagesizefromstring($webp);
        self::assertIsArray($info);
        self::assertSame(\IMAGETYPE_WEBP, $info[2]);

        return [$info[0], $info[1]];
    }

    /**
     * The built-in server re-runs preview-router.php from scratch for every
     * request, so a file under the system temp directory is the only way for
     * a test to observe which paths really reached the server.
     */
    private static function hitLogPath(): string
    {
        return sys_get_temp_dir().'/typo3-file-sync-preview-hits.log';
    }

    private static function resetHitLog(): void
    {
        @unlink(self::hitLogPath());
    }

    /**
     * @return list<string>
     */
    private static function hits(): array
    {
        $contents = @file_get_contents(self::hitLogPath());

        return false === $contents
            ? []
            : array_values(array_filter(explode(\PHP_EOL, $contents), static fn (string $line): bool => '' !== $line));
    }
}
