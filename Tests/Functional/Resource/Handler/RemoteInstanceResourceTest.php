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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Resource\Handler;

use GuzzleHttp\Client;
use KonradMichalik\Typo3FileSync\Resource\Handler\RemoteInstanceResource;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;

use function count;
use function is_resource;
use function sprintf;
use function strlen;

/**
 * RemoteInstanceResourceTest.
 *
 * Exercises the handler against a real HTTP server through Guzzle's real
 * handler stack. The unit tests mock ClientInterface and therefore never run
 * CurlFactory or RedirectMiddleware — which is exactly where response body
 * ownership is decided.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(RemoteInstanceResource::class)]
final class RemoteInstanceResourceTest extends TestCase
{
    private const EXPECTED_BODY_SIZE = 2048;

    /** @var resource|null */
    private static mixed $serverProcess = null;
    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $router = __DIR__.'/Fixtures/Server/router.php';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $port = self::findFreePort();
            $process = proc_open(
                [\PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
                $descriptors,
                $pipes,
            );

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
    }

    /**
     * A redirected response used to hand back an already closed resource,
     * making rewind() fail with "must be an open stream resource".
     *
     * @see https://github.com/konradmichalik/typo3-file-sync/pull/38
     */
    #[Test]
    #[DataProvider('fileRouteProvider')]
    public function getFileReturnsAnOpenReadableStream(string $filePath): void
    {
        $subject = new RemoteInstanceResource(self::$baseUrl, new Client());

        $result = $subject->getFile('1:/'.$filePath, $filePath);

        self::assertIsResource($result);
        self::assertSame('stream', get_resource_type($result));
        self::assertSame(self::EXPECTED_BODY_SIZE, strlen((string) stream_get_contents($result)));

        fclose($result);
    }

    /**
     * The stream must survive Guzzle's internals being garbage collected:
     * ownership is handed over to the caller, not shared with Guzzle.
     */
    #[Test]
    public function getFileReturnsAStreamThatOutlivesGarbageCollection(): void
    {
        $subject = new RemoteInstanceResource(self::$baseUrl, new Client());

        $result = $subject->getFile('1:/fileadmin/redirect.jpg', 'fileadmin/redirect.jpg');
        self::assertIsResource($result);

        gc_collect_cycles();

        self::assertIsResource($result);
        self::assertSame(self::EXPECTED_BODY_SIZE, strlen((string) stream_get_contents($result)));

        fclose($result);
    }

    #[Test]
    public function getFileReturnsFalseForMissingFiles(): void
    {
        $subject = new RemoteInstanceResource(self::$baseUrl, new Client());

        self::assertFalse($subject->getFile('1:/fileadmin/missing.jpg', 'fileadmin/missing.jpg'));
    }

    #[Test]
    public function prefetchedFilesAreReturnedWithoutAFurtherRequest(): void
    {
        self::resetHitLog();

        $subject = new RemoteInstanceResource(self::$baseUrl, new Client());
        $subject->prefetch(['fileadmin/batch-1.jpg', 'fileadmin/batch-2.jpg']);

        // The round trip must have happened during prefetch() itself. An
        // empty prefetch() body would still make the getFile() assertions
        // below pass, by fetching on demand instead, so this pins the
        // timing rather than just the final byte content.
        self::assertSame(1, self::countHitsFor('batch-1.jpg'));
        self::assertSame(1, self::countHitsFor('batch-2.jpg'));

        self::assertSame('body-for-batch-1.jpg', stream_get_contents($subject->getFile('1:/fileadmin/batch-1.jpg', 'fileadmin/batch-1.jpg')));
        self::assertSame('body-for-batch-2.jpg', stream_get_contents($subject->getFile('1:/fileadmin/batch-2.jpg', 'fileadmin/batch-2.jpg')));

        // getFile() must not have gone over the wire again for either path.
        self::assertSame(1, self::countHitsFor('batch-1.jpg'));
        self::assertSame(1, self::countHitsFor('batch-2.jpg'));
    }

    #[Test]
    public function prefetchNormalizesLeadingSlashesIntoTheSameBufferKeyGetFileUses(): void
    {
        self::resetHitLog();

        $subject = new RemoteInstanceResource(self::$baseUrl, new Client());
        $subject->prefetch(['/fileadmin/batch-1.jpg']);

        // A leading slash must not make prefetch() store under a different
        // key than getFile() looks up: a mismatch here would both miss the
        // buffer (leaking the resource) and, combined with array_unique(),
        // could request the same URL more than once per prefetch() call.
        self::assertSame(1, self::countHitsFor('batch-1.jpg'));

        self::assertSame('body-for-batch-1.jpg', stream_get_contents($subject->getFile('1:/fileadmin/batch-1.jpg', 'fileadmin/batch-1.jpg')));

        self::assertSame(1, self::countHitsFor('batch-1.jpg'));
    }

    #[Test]
    public function prefetchIgnoresFailuresAndLeavesTheRegularPathIntact(): void
    {
        $subject = new RemoteInstanceResource(self::$baseUrl, new Client());
        $subject->prefetch(['fileadmin/does-not-exist.jpg']);

        self::assertFalse($subject->getFile('1:/fileadmin/does-not-exist.jpg', 'fileadmin/does-not-exist.jpg'));
    }

    #[Test]
    public function aPrefetchedStreamIsHandedOutOnlyOnce(): void
    {
        self::resetHitLog();

        $subject = new RemoteInstanceResource(self::$baseUrl, new Client());
        $subject->prefetch(['fileadmin/batch-3.jpg']);

        $first = $subject->getFile('1:/fileadmin/batch-3.jpg', 'fileadmin/batch-3.jpg');
        self::assertIsResource($first);
        self::assertSame('body-for-batch-3.jpg', stream_get_contents($first));

        $second = $subject->getFile('1:/fileadmin/batch-3.jpg', 'fileadmin/batch-3.jpg');
        self::assertIsResource($second);
        self::assertSame('body-for-batch-3.jpg', stream_get_contents($second));

        // Two distinct handles, not the same one rewound and handed out
        // twice: reading a stream to EOF does not close it, so a rewind-
        // and-reuse implementation would still pass the assertions above.
        self::assertNotSame($first, $second);

        // The second read only reproduced the same body because it went
        // over the wire again, exactly once more.
        self::assertSame(2, self::countHitsFor('batch-3.jpg'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fileRouteProvider(): array
    {
        return [
            'direct response' => ['fileadmin/plain.jpg'],
            'single redirect' => ['fileadmin/redirect.jpg'],
            'redirect chain' => ['fileadmin/redirect-chain.jpg'],
            'gzip encoded' => ['fileadmin/gzip.jpg'],
        ];
    }

    /**
     * The built-in server re-runs router.php from scratch for every
     * request, so nothing in that process's memory survives between
     * requests. A file under the system temp directory is the only way for
     * a test to observe how many times a path actually reached the server.
     */
    private static function hitLogPath(): string
    {
        return sys_get_temp_dir().'/typo3-file-sync-batch-hits.log';
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
