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
use KonradMichalik\Typo3FileSync\Middleware\MaterializeMiddleware;
use KonradMichalik\Typo3FileSync\Service\MaterializationService;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{Response, ServerRequest, Stream};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_fill;
use function json_decode;
use function json_encode;

/**
 * MaterializeMiddlewareTest.
 *
 * Every fixture token here is deliberately unresolvable. The middleware
 * only has to shape the HTTP surface correctly; what a resolvable token
 * does once inside MaterializationService is covered by that service's
 * own functional test.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(MaterializeMiddleware::class)]
final class MaterializeMiddlewareTest extends FunctionalTestCase
{
    private const PATH = '/tx-file-sync/materialize';
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = false;

        // Under PHPUnit the entry script is vendor/bin/phpunit, which makes
        // TYPO3 read the site path as "vendor/bin/". Pinning it is what makes
        // the endpoint path assertable in either installation layout.
        $this->serverBackup = $_SERVER;
        self::useSitePath('/');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        GeneralUtility::flushInternalRuntimeCaches();
        parent::tearDown();
    }

    #[Test]
    public function returnsNotFoundWhileTheFeatureToggleIsOff(): void
    {
        $request = $this->buildRequest(self::PATH, 'POST', '{"tokens":["1.deadbeef"]}');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->get(MaterializeMiddleware::class)->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(json_encode(['error' => 'disabled']), (string) $response->getBody());
    }

    #[Test]
    public function rejectsAGetRequest(): void
    {
        $this->enableFeature();
        $request = $this->buildRequest(self::PATH, 'GET');

        $response = $this->get(MaterializeMiddleware::class)->process($request, $this->stubHandler());

        self::assertSame(405, $response->getStatusCode());
    }

    /**
     * @return array<string, list<string>>
     */
    public static function malformedBodyProvider(): array
    {
        return [
            'not json' => ['not json'],
            'no tokens key' => ['{}'],
            'empty tokens list' => ['{"tokens":[]}'],
            'tokens not a list' => ['{"tokens":"x"}'],
        ];
    }

    #[Test]
    #[DataProvider('malformedBodyProvider')]
    public function rejectsAMalformedBody(string $body): void
    {
        $this->enableFeature();
        $request = $this->buildRequest(self::PATH, 'POST', $body);

        $response = $this->get(MaterializeMiddleware::class)->process($request, $this->stubHandler());

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * The one branch of the 400 guard the provider above cannot reach, and
     * the reason the batch limit has to be MaterializationService's rather
     * than a copy: a drift between the two answers every full page with 400.
     */
    #[Test]
    public function rejectsABatchLargerThanTheServiceAccepts(): void
    {
        $this->enableFeature();
        $tokens = array_fill(0, MaterializationService::MAX_TOKENS + 1, '9999.deadbeef');
        $request = $this->buildRequest(self::PATH, 'POST', (string) json_encode(['tokens' => $tokens]));

        $response = $this->get(MaterializeMiddleware::class)->process($request, $this->stubHandler());

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function acceptsABatchOfExactlyTheAllowedSize(): void
    {
        $this->enableFeature();
        $tokens = array_fill(0, MaterializationService::MAX_TOKENS, '9999.deadbeef');
        $request = $this->buildRequest(self::PATH, 'POST', (string) json_encode(['tokens' => $tokens]));

        $response = $this->get(MaterializeMiddleware::class)->process($request, $this->stubHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function answersAWellFormedPostWithAJsonMap(): void
    {
        $this->enableFeature();
        $token = '9999.deadbeef';
        $request = $this->buildRequest(self::PATH, 'POST', (string) json_encode(['stage' => 'original', 'tokens' => [$token]]));

        $response = $this->get(MaterializeMiddleware::class)->process($request, $this->stubHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $decoded = json_decode((string) $response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey($token, $decoded);
    }

    #[Test]
    public function neverReachesTheNextHandler(): void
    {
        $this->enableFeature();
        $token = '9999.deadbeef';
        $request = $this->buildRequest(self::PATH, 'POST', (string) json_encode(['tokens' => [$token]]));

        $response = $this->get(MaterializeMiddleware::class)->process($request, $this->stubHandler());

        self::assertNotSame(418, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughForAnyOtherPath(): void
    {
        $request = $this->buildRequest('/some-page', 'GET');

        $response = $this->get(MaterializeMiddleware::class)->process($request, $this->stubHandler());

        self::assertSame(418, $response->getStatusCode());
    }

    /**
     * A request to a site below a subdirectory arrives carrying that
     * subdirectory. Matching the bare root path would hand every materialize
     * call straight to the page renderer, and the module would be posting at
     * an address that answers with a page.
     */
    #[Test]
    public function answersAtTheEndpointOfASubdirectoryInstall(): void
    {
        self::useSitePath('/subdir/');
        $this->enableFeature();

        self::assertSame('/subdir'.self::PATH, MaterializeMiddleware::endpointPath());

        $response = $this->get(MaterializeMiddleware::class)->process(
            $this->buildRequest('/subdir'.self::PATH, 'POST', '{"tokens":["9999.deadbeef"]}'),
            $this->stubHandler(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughTheRootPathOnASubdirectoryInstall(): void
    {
        self::useSitePath('/subdir/');
        $this->enableFeature();

        $response = $this->get(MaterializeMiddleware::class)->process(
            $this->buildRequest(self::PATH, 'POST', '{"tokens":["9999.deadbeef"]}'),
            $this->stubHandler(),
        );

        self::assertSame(418, $response->getStatusCode());
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

    private function enableFeature(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = true;
    }

    private function buildRequest(string $path, string $method, string $body = ''): ServerRequest
    {
        $stream = new Stream('php://temp', 'r+');
        $stream->write($body);

        return (new ServerRequest('https://example.com'.$path, $method, $stream))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
    }

    /**
     * A handler that would answer 418 if it were ever reached, so a test
     * that forgot to assert on it would still fail loudly.
     */
    private function stubHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response('php://temp', 418));

        return $handler;
    }
}
