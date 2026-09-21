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
use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, MaterializationService};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function count;
use function is_resource;
use function sprintf;

/**
 * MaterializationServiceTest.
 *
 * Runs against a real local storage backed by the FileSyncDriver and a real
 * HTTP server, because everything this service does happens between FAL and
 * the remote: mocking either end would leave the interesting part untested.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(MaterializationService::class)]
final class MaterializationServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    /** @var resource|null */
    private static mixed $serverProcess = null;
    private static string $baseUrl = '';

    private string $basePath;

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
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.com/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);

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
        GeneralUtility::rmdir($this->basePath, true);
        putenv('TYPO3_FILE_SYNC_REMOTE_URL');
        parent::tearDown();
    }

    #[Test]
    public function materializeReplacesTheProvisionalFileAndReturnsABustedUrl(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(MaterializationService::class)->materialize([$token]);

        self::assertArrayHasKey('url', $result[$token]);
        self::assertStringContainsString('?v=', $result[$token]['url']);
        self::assertSame('remote-body', file_get_contents($this->basePath.'user_upload/provisional.jpg'));
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

    #[Test]
    public function anInvalidTokenYieldsAnErrorEntryRatherThanAnException(): void
    {
        $result = $this->get(MaterializationService::class)->materialize(['9999.deadbeef']);

        self::assertSame(['9999.deadbeef' => ['error' => 'invalid']], $result);
    }

    #[Test]
    public function anOriginalRetriedWithinTheDampingWindowIsRejected(): void
    {
        $tokenService = $this->get(DeferredTokenService::class);
        $service = $this->get(MaterializationService::class);

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
        $targetName = $this->get(ProcessedFileRepository::class)
            ->findByUid($processedFileUid)
            ->getTask()
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

    private function renditionExists(int $processedFileUid): bool
    {
        return (bool) $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_processedfile')
            ->count('uid', 'sys_file_processedfile', ['uid' => $processedFileUid]);
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
