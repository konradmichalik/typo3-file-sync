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
use KonradMichalik\Typo3FileSync\Resource\Preview\PreviewStore;
use KonradMichalik\Typo3FileSync\Service\{DeferredTokenService, PreviewService};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_filter;
use function array_values;
use function base64_decode;
use function base64_encode;
use function explode;
use function file_get_contents;
use function is_resource;
use function sprintf;

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
final class PreviewServiceTest extends FunctionalTestCase
{
    private const ORIGINAL_IDENTIFIER = '/user_upload/provisional.jpg';
    private const RENDITION_PATH = '/fileadmin/_processed_/csm_provisional_small.jpg';
    private const STORAGE = 9;

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

        $router = __DIR__.'/Fixtures/Server/preview-router.php';
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
        $this->importCSVDataSet(__DIR__.'/Fixtures/preview.csv');

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = true;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_PREVIEW_IMAGES] = true;
        $globalRequest = (new ServerRequest('https://example.com/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $globalRequest
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($globalRequest));

        $this->serverBackup = $_SERVER;
        self::useSitePath('/');

        // The local driver takes its storage offline when the base path is
        // missing, and an offline storage hands out no driver to prefetch
        // with. Nothing below this line reads a local file.
        $this->basePath = Environment::getPublicPath().'/fileadmin/';
        GeneralUtility::mkdir_deep($this->basePath.'user_upload');
        GeneralUtility::mkdir_deep($this->basePath.'_processed_');

        self::resetHitLog();
    }

    protected function tearDown(): void
    {
        (new PreviewStore())->remove(self::STORAGE, self::ORIGINAL_IDENTIFIER);
        unset($GLOBALS['TYPO3_REQUEST']);
        $_SERVER = $this->serverBackup;
        GeneralUtility::flushInternalRuntimeCaches();
        GeneralUtility::rmdir($this->basePath, true);
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
        (new PreviewStore())->write(self::STORAGE, self::ORIGINAL_IDENTIFIER, 'stored-preview-bytes');
        $token = $this->get(DeferredTokenService::class)->create(10);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(
            ['preview' => 'data:image/webp;base64,'.base64_encode('stored-preview-bytes')],
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

        $webp = base64_decode(explode(',', $result[$token]['preview'], 2)[1], true);
        self::assertIsString($webp);
        $info = getimagesizefromstring($webp);
        self::assertIsArray($info);
        self::assertSame(\IMAGETYPE_WEBP, $info[2]);
        // Derived from the requested rendition (300x200), not from the
        // 150x100 payload that was actually downloaded.
        self::assertSame([32, 21], [$info[0], $info[1]]);

        self::assertTrue((new PreviewStore())->has(self::STORAGE, self::ORIGINAL_IDENTIFIER));
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
        self::assertSame([self::RENDITION_PATH], self::hits());
    }

    #[Test]
    public function aFileWithoutAnyRenditionYieldsUnavailable(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(40);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertSame([], self::hits());
    }

    #[Test]
    public function aNotFoundFromTheRemoteYieldsUnavailable(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(20);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
    }

    #[Test]
    public function aRemotePayloadThatIsNotAnImageYieldsUnavailable(): void
    {
        $token = $this->get(DeferredTokenService::class)->create(30);

        $result = $this->get(PreviewService::class)->preview([$token]);

        self::assertSame(['error' => 'unavailable'], $result[$token]);
        self::assertFalse((new PreviewStore())->has(self::STORAGE, '/user_upload/fallback.jpg'));
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
