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
use KonradMichalik\Typo3FileSync\Service\DeferredTokenService;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\{Response, ServerRequest, Stream};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function preg_match;
use function str_repeat;
use function strlen;
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

    private const REAL_TAG = '<img src="/fileadmin/_processed_/a/b/csm_real_bbb.jpg" alt="real">';

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
        $this->get(CacheManager::class)->getCache('runtime')->flush();
        $this->get(CacheManager::class)->getCache('hash')->flush();
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

    #[Test]
    public function injectsTheScriptAndStyleExactlyOnce(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/provisional_images.csv');

        $result = $this->processBody($this->page(str_repeat(self::PROVISIONAL_TAG, 10)));

        self::assertSame(10, substr_count($result, 'data-file-sync='));
        self::assertSame(1, substr_count($result, 'file-sync.js'));
        self::assertSame(1, substr_count($result, '::view-transition-old'));
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
