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

namespace KonradMichalik\Typo3FileSync\Tests\Functional;

use KonradMichalik\Typo3FileSync\Configuration;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_resource;
use function sprintf;

/**
 * RemoteInstanceHarness.
 *
 * What a test needs in order to run this extension against a real remote:
 * a PHP built-in server on a free port, a frontend request in the globals,
 * and a fileadmin on disk. Two test classes need all three, and neither is
 * testing the harness, so it lives here rather than twice in them.
 *
 * Nothing here decides policy. Whether a server that will not start is a
 * skipped test or a failed one differs between the two classes and stays
 * with them.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
trait RemoteInstanceHarness
{
    /** @var resource|null */
    private static mixed $serverProcess = null;

    private static string $baseUrl = '';

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    private string $basePath = '';

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        parent::tearDownAfterClass();
    }

    /**
     * Three attempts, because the port was free when it was looked up and
     * another process may have taken it in between.
     */
    protected static function startServer(string $router): bool
    {
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
                return true;
            }

            self::stopServer();
        }

        return false;
    }

    protected static function stopServer(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }

        self::$serverProcess = null;
    }

    /**
     * TYPO3 derives the site path from the entry script and the request, both
     * of which are meaningless under PHPUnit. Pointing them at an index.php
     * below $sitePath is what a real installation at that path looks like.
     */
    protected static function useSitePath(string $sitePath): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = $sitePath.'index.php';
        $_SERVER['REQUEST_URI'] = $sitePath;
        GeneralUtility::flushInternalRuntimeCaches();
    }

    /**
     * The globals a frontend request on a deferred storage leaves behind,
     * which is precisely the situation in which the driver refuses to fetch.
     * Without them the services under test would run in a mode they never
     * see in production.
     *
     * TYPO3 v14 resolves an extension asset URL through the system resource
     * publisher, which falls back to $GLOBALS['TYPO3_REQUEST'] and reads
     * normalizedParams off it. Core sets that attribute early in every real
     * frontend request, so a global without it models an installation that
     * cannot exist.
     */
    protected function enterFrontendRequest(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = true;
        $request = (new ServerRequest('https://example.com/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $GLOBALS['TYPO3_REQUEST'] = $request
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $this->serverBackup = $_SERVER;
        self::useSitePath('/');
    }

    protected function leaveFrontendRequest(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        $_SERVER = $this->serverBackup;
        GeneralUtility::flushInternalRuntimeCaches();
    }

    /**
     * The local driver takes its storage offline when the base path is
     * missing, and an offline storage hands out no driver to prefetch with.
     */
    protected function scaffoldFileadmin(): string
    {
        $this->basePath = Environment::getPublicPath().'/fileadmin/';
        GeneralUtility::mkdir_deep($this->basePath.'user_upload');
        GeneralUtility::mkdir_deep($this->basePath.'_processed_');

        return $this->basePath;
    }

    protected function removeFileadmin(): void
    {
        GeneralUtility::rmdir($this->basePath, true);
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
}
