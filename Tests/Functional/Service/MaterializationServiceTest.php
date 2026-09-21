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
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

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
