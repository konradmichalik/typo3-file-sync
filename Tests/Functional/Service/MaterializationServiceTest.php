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
use KonradMichalik\Typo3FileSync\Middleware\DeferredImageMiddleware;
use KonradMichalik\Typo3FileSync\Resource\Handler\RemoteInstanceResource;
use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, MaterializationService};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\{NormalizedParams, Response, ServerRequest, Stream};
use TYPO3\CMS\Core\Resource\{ProcessedFileRepository, ResourceFactory};
use TYPO3\CMS\Core\Resource\Processing\TaskTypeRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_filter;
use function array_values;
use function count;
use function file_get_contents;
use function is_resource;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * MaterializationServiceTest.
 *
 * Runs against a real local storage backed by the FileSyncDriver and a real
 * HTTP server, because everything this service does happens between FAL and
 * the remote: mocking either end would leave the interesting part untested.
 *
 * One test here deliberately spans two middlewares' worth of the feature:
 * it mints its token with DeferredImageMiddleware instead of by hand, so the
 * URL this service answers with is checked against the very src attribute it
 * has to replace. Nothing else in the suite crosses that seam.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(DeferredImageMiddleware::class)]
#[CoversClass(MaterializationService::class)]
final class MaterializationServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    /** @var resource|null */
    private static mixed $serverProcess = null;
    private static string $baseUrl = '';

    private string $basePath;

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $router = __DIR__.'/Fixtures/Server/router.php';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $port = self::findFreePort();
            $process = proc_open([\PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], $descriptors, $pipes);

            if (!is_resource($process)) {
                continue;
            }

            self::$serverProcess = $process;
            self::$baseUrl = 'http://127.0.0.1:'.$port;

            if (self::waitForServer($port)) {
                return;
            }

            self::stopServer();
        }

        self::markTestSkipped('The PHP built-in server did not become reachable.');
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        // The handler resolves this placeholder in its constructor, which
        // runs the first time FAL initialises the storage.
        putenv('TYPO3_FILE_SYNC_REMOTE_URL='.self::$baseUrl);

        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/materialization.csv');

        // The endpoint this service backs is reached by a frontend request
        // on a storage with deferred loading on, which is precisely the
        // situation in which the driver refuses to fetch. Without both of
        // these the service would be exercised in a mode it never runs in.
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = true;
        $globalRequest = (new ServerRequest('https://example.com/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        // TYPO3 v14 resolves an extension asset URL through the system
        // resource publisher, which falls back to $GLOBALS['TYPO3_REQUEST']
        // and reads normalizedParams off it. Core sets that attribute early
        // in every real frontend request, so a global without it models an
        // installation that cannot exist.
        $GLOBALS['TYPO3_REQUEST'] = $globalRequest
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($globalRequest));

        // Under PHPUnit the entry script is vendor/bin/phpunit, which makes
        // TYPO3 read the site path as "vendor/bin/". Pinning it is what lets
        // a test assert the URL the browser is handed, and lets another move
        // the whole site into a subdirectory.
        $this->serverBackup = $_SERVER;
        self::useSitePath('/');

        $this->basePath = Environment::getPublicPath().'/fileadmin/';
        GeneralUtility::mkdir_deep($this->basePath.'user_upload');
        GeneralUtility::mkdir_deep($this->basePath.'_processed_');
        file_put_contents($this->basePath.'user_upload/provisional.jpg', 'placeholder-body');
        file_put_contents($this->basePath.'user_upload/broken.txt', 'placeholder-body');
        file_put_contents($this->basePath.'user_upload/fallback.jpg', 'placeholder-body');
        file_put_contents($this->basePath.'_processed_/csm_provisional.jpg', 'placeholder-derivative');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $_SERVER = $this->serverBackup;
        GeneralUtility::flushInternalRuntimeCaches();
        GeneralUtility::rmdir($this->basePath, true);
        putenv('TYPO3_FILE_SYNC_REMOTE_URL');
        parent::tearDown();
    }

    /**
     * The rendition is moved aside rather than deleted, so a rebuild that
     * cannot finish leaves the already cached HTML pointing at bytes that are
     * still there. Deleting it would be permanent: refetchOriginal() has by
     * now marked the original remote_instance, so no later render classifies
     * this rendition as provisional and nothing ever retries it.
     */
    #[Test]
    public function aRenditionSurvivesARebuildThatCannotFinish(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_processedfile')->update(
            'sys_file_processedfile',
            ['task_type' => 'Image.NoSuchTask'],
            ['uid' => 10],
        );

        $result = $this->get(MaterializationService::class)
            ->materialize([$this->get(DeferredTokenService::class)->create(10)]);

        self::assertSame(['error' => 'unavailable'], array_values($result)[0]);
        self::assertFileExists($this->basePath.'_processed_/csm_provisional.jpg');
        self::assertSame('placeholder-derivative', file_get_contents($this->basePath.'_processed_/csm_provisional.jpg'));
    }

    #[Test]
    public function materializeReplacesTheProvisionalFileAndReturnsABustedUrl(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(MaterializationService::class)->materialize([$token]);

        // The shape matters, not just the presence of a key: this is the one
        // value the browser consumes. A site-relative "fileadmin/..." would
        // satisfy any weaker assertion and still resolve against the page
        // the visitor is on, which is a 404 on every page but the root.
        self::assertMatchesRegularExpression(
            '#^/fileadmin/[^?\s]+\.jpg\?v=\d+$#',
            $result[$token]['url'],
        );
        self::assertSame('remote-body', file_get_contents($this->basePath.'user_upload/provisional.jpg'));
    }

    #[Test]
    public function theRebuiltUrlCarriesTheSitePathOfASubdirectoryInstall(): void
    {
        self::useSitePath('/subdir/');
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(MaterializationService::class)->materialize([$token]);

        self::assertMatchesRegularExpression(
            '#^/subdir/fileadmin/[^?\s]+\.jpg\?v=\d+$#',
            $result[$token]['url'],
        );
    }

    /**
     * The other half of the same seam: the module the marking side injects
     * has to post to the same path the endpoint answers at, and only the
     * server knows where the site root is.
     */
    #[Test]
    public function theInjectedModuleIsPointedAtTheEndpointOfASubdirectoryInstall(): void
    {
        self::useSitePath('/subdir/');

        $marked = $this->markBody('<html><body><img src="/fileadmin/_processed_/csm_provisional.jpg"></body></html>');

        self::assertStringContainsString(
            'data-file-sync-endpoint="/subdir/tx-file-sync/materialize"',
            $marked,
        );
    }

    /**
     * The script tag's own src has to be rooted the same way the endpoint is.
     * Letting PathUtility prefix it would derive the prefix from the request,
     * which on v14 reaches the system resource publisher and its fallback to
     * a global that only middlewares running inside this one populate.
     */
    #[Test]
    public function theInjectedModuleIsLoadedFromTheSitePathOfASubdirectoryInstall(): void
    {
        self::useSitePath('/subdir/');

        $marked = $this->markBody('<html><body><img src="/fileadmin/_processed_/csm_provisional.jpg"></body></html>');

        self::assertSame(1, preg_match('#<script type="module" src="([^"]+)"#', $marked, $matches));
        self::assertStringStartsWith('/subdir/', $matches[1]);
        self::assertStringEndsWith('file-sync.js', $matches[1]);
    }

    /**
     * The only test that crosses a task boundary. The marking middleware and
     * the materialization service each define what a provisional image URL
     * looks like, and both sides were green while the two definitions did not
     * meet: the middleware stripped a rooted src, the service answered with
     * an unrooted one, and no browser ever swapped an image outside the site
     * root.
     */
    #[Test]
    public function aTokenMintedByTheMarkingMiddlewareYieldsAReplacementForTheSrcItMarked(): void
    {
        $src = '/fileadmin/_processed_/csm_provisional.jpg';
        $marked = $this->markBody('<html><body><img src="'.$src.'" alt="deferred"></body></html>');

        self::assertSame(1, preg_match('/data-file-sync="([^"]+)"/', $marked, $matches));

        $token = $matches[1];
        $result = $this->get(MaterializationService::class)->materialize([$token]);

        self::assertArrayHasKey('url', $result[$token], 'The minted token did not resolve to a rendition.');

        // Prefix compatible: the replacement has to be reachable from the
        // same document as the src it replaces, which means it carries the
        // storage's public prefix exactly as the marked src did. That prefix
        // is derived from the src rather than written out, so the marking
        // side and the answering side cannot drift apart unnoticed.
        //
        // Compared at the storage root rather than at the rendition folder
        // because without an image processor core hands back the original
        // file as its own rendition, which is a different folder.
        self::assertStringStartsWith(
            substr($src, 0, (int) strpos($src, '/', 1) + 1),
            $result[$token]['url'],
        );
    }

    #[Test]
    public function materializeClearsTheProvisionalMarkerOnTheOriginal(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(10);
        $this->get(MaterializationService::class)->materialize([$token]);

        $row = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['tx_typo3_file_sync_identifier'], 'sys_file', ['uid' => 1])
            ->fetchAssociative();

        self::assertSame('remote_instance', $row['tx_typo3_file_sync_identifier']);
    }

    #[Test]
    public function aFileOnlyTheFallbackHandlerCouldDeliverIsReportedAsUnavailable(): void
    {
        // uid 3's original is an image the fixture server answers with 404,
        // so the placeholder handler puts a file on disk. That is not a
        // materialization: answering with a url would make the browser swap
        // a placeholder for a placeholder and stop retrying that image.
        $token = $this->get(DeferredTokenService::class)->create(30);

        $result = $this->get(MaterializationService::class)->materialize([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertFileExists($this->basePath.'user_upload/fallback.jpg');
        self::assertSame('placeholder_image', $this->syncIdentifierOf(3));
    }

    /**
     * The reachable case is ordinary rather than exotic: an instance whose
     * files were rsynced from production after its database was synced sits
     * at an identifier of placeholder_image with the real bytes already on
     * disk, and the marking query keeps minting tokens for those. Deleting
     * first and fetching second would destroy one real file per page view.
     */
    #[Test]
    public function aFailedFetchLeavesTheOriginalOnDiskAsItWas(): void
    {
        // uid 2's original is a text file the fixture server answers with 404
        // and the placeholder handler refuses, so no handler delivers at all.
        file_put_contents($this->basePath.'user_upload/broken.txt', 'bytes-that-were-already-there');
        $token = $this->get(DeferredTokenService::class)->create(20);

        $result = $this->get(MaterializationService::class)->materialize([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertSame(
            'bytes-that-were-already-there',
            file_get_contents($this->basePath.'user_upload/broken.txt'),
        );
        self::assertSame([], glob($this->basePath.'user_upload/.tx-file-sync-stash-*'));
    }

    #[Test]
    public function theProvisionalRenditionIsDiscardedInsteadOfAdopted(): void
    {
        $targetName = $this->parkRenditionAtItsTargetName(10);
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(MaterializationService::class)->materialize([$token]);

        // Left in place, a provisional rendition is not simply overwritten.
        // Core either adopts whatever already sits at the target name
        // (LocalImageProcessor::checkForExistingTargetFile()) or deletes it
        // mid-flight from needsReprocessing() and hands back a ProcessedFile
        // already flagged deleted, whose getPublicUrl() is null. Discarding
        // it up front is what makes the answer a real, current rendition.
        self::assertArrayHasKey('url', $result[$token]);
        self::assertStringNotContainsString($targetName, $result[$token]['url']);
        self::assertFalse($this->renditionExists(10));
    }

    #[Test]
    public function anOriginalMissingFromDiskIsFetchedOnceRatherThanDownloadedAndDeleted(): void
    {
        // Nothing pre-seeded here: this is the state the driver reaches
        // whenever a provisional original was already cleaned off disk.
        unlink($this->basePath.'user_upload/provisional.jpg');
        self::resetHitLog();
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(MaterializationService::class)->materialize([$token]);

        // getForLocalProcessing() fetches when the file is absent, so a
        // delete guard read before it downloads the real original and
        // unlinks it again. prefetch() hands each buffered stream out
        // once, so recovering from that costs a second trip over the wire.
        self::assertSame(1, self::countHitsFor('provisional.jpg'));
        self::assertArrayHasKey('url', $result[$token]);
    }

    #[Test]
    public function thePrefetchedBufferIsTheOneTheDriverReads(): void
    {
        self::resetHitLog();
        $token = $this->get(DeferredTokenService::class)->create(10);

        $this->get(MaterializationService::class)->materialize([$token]);

        // prefetch() keys its buffer by the path the driver later looks up.
        // Derive the two differently and the buffer is filled but never
        // read: the driver silently falls back to fetching serially, and
        // the only trace is a second request for the same file.
        self::assertSame(1, self::countHitsFor('provisional.jpg'));
    }

    #[Test]
    public function aRenditionOfAnAlreadyMaterializedOriginalIsNotThrottled(): void
    {
        $tokenService = $this->get(DeferredTokenService::class);
        $service = $this->get(MaterializationService::class);

        $service->materialize([$tokenService->create(10)]);
        self::resetHitLog();

        // Lazy loading sends a second batch on scroll. updateIdentifier()
        // stamps tx_typo3_file_sync_tstamp on success exactly as damp()
        // does on failure, so reading that stamp as "failed recently"
        // would leave every further rendition a placeholder for 300s.
        $retryToken = $tokenService->create(11);
        $second = $service->materialize([$retryToken]);

        self::assertArrayHasKey('url', $second[$retryToken]);

        // And letting the token through must not mean downloading the
        // original again: it is already the real file on disk, so the
        // second batch has nothing to fetch for it.
        self::assertSame(0, self::countHitsFor('provisional.jpg'));
    }

    #[Test]
    public function severalRenditionsOfOneOriginalCauseASingleRemoteFetch(): void
    {
        self::resetHitLog();
        $tokenService = $this->get(DeferredTokenService::class);
        $tokens = [$tokenService->create(10), $tokenService->create(11), $tokenService->create(12)];

        $result = $this->get(MaterializationService::class)->materialize($tokens);

        // Without grouping by original, rendition two and three would each
        // delete the real file rendition one just downloaded and fetch it
        // again, which is three round trips and a window in which the real
        // file is missing from disk.
        self::assertSame(1, self::countHitsFor('provisional.jpg'));
        foreach ($tokens as $token) {
            self::assertArrayHasKey('url', $result[$token]);
        }
    }

    /**
     * DeferrableResourceInterface is documented as not being an extension
     * point, because this service and FileRepository hold two definitions of
     * "provisional" that agree only while one handler is marked deferrable.
     * The day a project ignores that, the image is materialized here and
     * deferred again by the next render, forever. A log line is what makes
     * that visible instead of silent.
     */
    #[Test]
    public function aSecondDeferrableHandlerOnAStorageIsReportedAsUnsupported(): void
    {
        $this->registerASecondDeferrableHandler();

        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $service = $this->get(MaterializationService::class);
        $service->setLogger($logger);
        $service->materialize([$this->get(DeferredTokenService::class)->create(10)]);

        $warnings = array_values(array_filter(
            $logger->messages,
            static fn (string $message): bool => str_contains($message, 'deferrable resource handlers'),
        ));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('second_remote', $warnings[0]);
        self::assertStringContainsString('DeferrableResourceInterface', $warnings[0]);
    }

    #[Test]
    public function anInvalidTokenYieldsAnErrorEntryRatherThanAnException(): void
    {
        $result = $this->get(MaterializationService::class)->materialize(['9999.deadbeef']);

        self::assertSame(['9999.deadbeef' => ['error' => 'invalid']], $result);
    }

    /**
     * The other half of the placeholder case below: a remote that really
     * cannot deliver still has to be left alone for the window, and the
     * failure is recorded where nothing else means anything by it. The sync
     * timestamp is not that place, since the backend shows it as the moment
     * a handler delivered this file.
     */
    #[Test]
    public function anOriginalRetriedWithinTheDampingWindowIsRejected(): void
    {
        $tokenService = $this->get(DeferredTokenService::class);
        $service = $this->get(MaterializationService::class);
        $this->setSyncTimestampOf(2, 1700000000);

        // uid 20's original is a text file the fixture server answers with
        // 404 and the placeholder handler refuses, so no handler delivers.
        $failingToken = $tokenService->create(20);
        $first = $service->materialize([$failingToken]);

        // Discarding a rendition takes its sys_file_processedfile record
        // with it, so a retry never reuses the old token: the next render
        // produces a new rendition of the same original. That is why the
        // damping window is stored on the original, not on the rendition.
        $this->addRenditionForBrokenOriginal();
        $retryToken = $tokenService->create(21);

        self::assertSame(['error' => 'unavailable'], $first[$failingToken]);
        self::assertSame(['error' => 'throttled'], $service->materialize([$retryToken])[$retryToken]);
        self::assertSame(1700000000, $this->syncTimestampOf(2));
    }

    /**
     * The first visit to a freshly synced installation, which is the whole
     * point of the feature. The render that put the grey placeholder on the
     * page stamps the file as it delivers, and the browser posts its tokens
     * milliseconds later. Read as a recent failure, that stamp throttles
     * every image of every page until five minutes after the render, so the
     * visitor only ever sees real files on a much later reload.
     */
    #[Test]
    public function aPlaceholderRenderedByThisPageViewDoesNotThrottleItsOwnMaterialization(): void
    {
        unlink($this->basePath.'user_upload/provisional.jpg');
        // Exactly what the render does: the deferred storage skips the
        // remote handler, the placeholder handler answers, and delivering
        // writes the identifier and the sync timestamp.
        $this->get(ResourceFactory::class)->getFileObject(1)->getForLocalProcessing(false);
        self::assertSame('placeholder_image', $this->syncIdentifierOf(1));

        $token = $this->get(DeferredTokenService::class)->create(10);
        $result = $this->get(MaterializationService::class)->materialize([$token]);

        self::assertArrayHasKey('url', $result[$token], 'The placeholder this render wrote damped its own replacement.');
        self::assertSame('remote-body', file_get_contents($this->basePath.'user_upload/provisional.jpg'));
    }

    #[Test]
    public function moreThanFiftyTokensAreRejectedWholesale(): void
    {
        $tokens = array_map(fn (int $i): string => $this->get(DeferredTokenService::class)->create($i), range(1, 51));

        self::assertSame([], $this->get(MaterializationService::class)->materialize($tokens));
    }

    /**
     * TYPO3 names a rendition csm_<name>_<checksum>.<ext> and the image
     * processor short-circuits on a file already sitting at that name. A
     * fixture whose rendition is parked under any other name would let the
     * discard step look inert when it is not.
     */
    private function parkRenditionAtItsTargetName(int $processedFileUid): string
    {
        $processedFile = $this->get(ProcessedFileRepository::class)->findByUid($processedFileUid);
        // ProcessedFile::getTask() was removed in TYPO3 v14. This is what
        // v13's getTask() does internally, and both versions expose it.
        $targetName = $this->get(TaskTypeRegistry::class)
            ->getTaskForType(
                $processedFile->getTaskIdentifier(),
                $processedFile,
                $processedFile->getProcessingConfiguration(),
            )
            ->getTargetFileName();

        unlink($this->basePath.'_processed_/csm_provisional.jpg');
        file_put_contents($this->basePath.'_processed_/'.$targetName, 'placeholder-derivative');
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_processedfile')->update(
            'sys_file_processedfile',
            ['identifier' => '/_processed_/'.$targetName, 'name' => $targetName],
            ['uid' => $processedFileUid],
        );

        return $targetName;
    }

    /**
     * Points the storage at a second handler that is also deferrable. Its
     * class is RemoteInstanceResource again, because what the warning reacts
     * to is a second deferrable identifier on one storage, not a particular
     * implementation. Registering it through EXTCONF means blanking the
     * record's own resource field, which is what makes that path win.
     */
    private function registerASecondDeferrableHandler(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_RESOURCE_HANDLER]['second_remote'] = [
            'title' => 'Second Remote',
            'handler' => RemoteInstanceResource::class,
        ];
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES][9] = [
            ['identifier' => 'remote_instance', 'configuration' => self::$baseUrl],
            ['identifier' => 'second_remote', 'configuration' => self::$baseUrl],
        ];

        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')->update(
            'sys_file_storage',
            [Configuration::FIELD_RESOURCES => ''],
            ['uid' => 9],
        );
    }

    /**
     * Runs the marking middleware over a response body the way the frontend
     * would, so the token under test is the one a real page would carry.
     */
    private function markBody(string $body): string
    {
        $stream = new Stream('php://temp', 'r+');
        $stream->write($body);
        $response = (new Response($stream, 200))->withHeader('Content-Type', 'text/html; charset=utf-8');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $request = (new ServerRequest('https://example.com/en/news/article-42/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);

        return (string) $this->get(DeferredImageMiddleware::class)->process($request, $handler)->getBody();
    }

    private function renditionExists(int $processedFileUid): bool
    {
        return (bool) $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_processedfile')
            ->count('uid', 'sys_file_processedfile', ['uid' => $processedFileUid]);
    }

    private function setSyncTimestampOf(int $fileUid, int $tstamp): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->update(
            'sys_file',
            [Configuration::FIELD_TSTAMP => $tstamp],
            ['uid' => $fileUid],
        );
    }

    private function syncTimestampOf(int $fileUid): int
    {
        $row = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select([Configuration::FIELD_TSTAMP], 'sys_file', ['uid' => $fileUid])
            ->fetchAssociative();

        return (int) ($row[Configuration::FIELD_TSTAMP] ?? 0);
    }

    private function syncIdentifierOf(int $fileUid): string
    {
        $row = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['tx_typo3_file_sync_identifier'], 'sys_file', ['uid' => $fileUid])
            ->fetchAssociative();

        return (string) ($row['tx_typo3_file_sync_identifier'] ?? '');
    }

    private function addRenditionForBrokenOriginal(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_processedfile')->insert(
            'sys_file_processedfile',
            [
                'uid' => 21,
                'storage' => 9,
                'original' => 2,
                'identifier' => '/_processed_/csm_broken_retry.png',
                'name' => 'csm_broken_retry.png',
                'configuration' => 'a:2:{s:5:"width";i:300;s:6:"height";i:200;}',
                'configurationsha1' => '5bcf4b3ce884cacbb71271b88389cd42775c9c55',
                'task_type' => 'Image.CropScaleMask',
                'width' => 300,
                'height' => 200,
            ],
        );
    }

    /**
     * The built-in server re-runs router.php from scratch for every
     * request, so a file under the system temp directory is the only way
     * for a test to observe how often a path really reached the server.
     */
    private static function hitLogPath(): string
    {
        return sys_get_temp_dir().'/typo3-file-sync-materialize-hits.log';
    }

    private static function resetHitLog(): void
    {
        @unlink(self::hitLogPath());
    }

    private static function countHitsFor(string $filename): int
    {
        $contents = @file_get_contents(self::hitLogPath());
        if (false === $contents) {
            return 0;
        }

        return count(array_filter(
            explode(\PHP_EOL, $contents),
            static fn (string $line): bool => $filename === $line,
        ));
    }

    /**
     * TYPO3 derives the site path from the entry script and the request, both
     * of which are meaningless under PHPUnit. Pointing them at an index.php
     * below $sitePath is what a real installation at that path looks like.
     */
    private static function useSitePath(string $sitePath): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = $sitePath.'index.php';
        $_SERVER['REQUEST_URI'] = $sitePath;
        GeneralUtility::flushInternalRuntimeCaches();
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (false === $socket) {
            self::markTestSkipped(sprintf('Could not allocate a port: %s (%d)', $errstr, $errno));
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForServer(int $port): bool
    {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($connection)) {
                fclose($connection);

                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }

        self::$serverProcess = null;
    }
}
