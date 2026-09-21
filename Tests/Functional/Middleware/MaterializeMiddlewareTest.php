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
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{Response, ServerRequest, Stream};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

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

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = false;
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
