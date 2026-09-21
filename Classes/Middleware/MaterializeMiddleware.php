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

namespace KonradMichalik\Typo3FileSync\Middleware;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Service\{MaterializationService, MaterializeRateLimiter, SitePath};
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Configuration\Features;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function is_array;
use function is_scalar;
use function json_decode;
use function json_encode;
use function rtrim;
use function strval;

/**
 * MaterializeMiddleware.
 *
 * The HTTP surface for the materialization service: a fixed frontend path
 * answered before site resolution and TSFE, so a materialize call never
 * pays for routing or page rendering.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class MaterializeMiddleware implements MiddlewareInterface
{
    private const PATH = '/tx-file-sync/materialize';

    public function __construct(
        private Features $features,
        private MaterializationService $materializationService,
        private MaterializeRateLimiter $rateLimiter,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * The path the browser has to post to. Below a subdirectory install the
     * request arrives carrying that subdirectory, so a fixed root path would
     * both miss here and be unreachable from the module.
     */
    public static function endpointPath(): string
    {
        return rtrim(SitePath::prefix(), '/').self::PATH;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::endpointPath() !== $request->getUri()->getPath()) {
            return $handler->handle($request);
        }

        if (!$this->features->isFeatureEnabled(Configuration::FEATURE_DEFERRED_LOADING)) {
            return $this->json(['error' => 'disabled'], 404);
        }

        // Counted before the method check, so a flood cannot dodge the limit
        // by using a verb that would be rejected cheaply.
        if (!$this->rateLimiter->isAccepted($request)) {
            return $this->json(['error' => 'too many requests'], 429);
        }

        if ('POST' !== $request->getMethod()) {
            return $this->json(['error' => 'method not allowed'], 405);
        }

        $tokens = self::readTokens(json_decode((string) $request->getBody(), true));
        if (null === $tokens) {
            return $this->json(['error' => 'bad request'], 400);
        }

        return $this->json($this->materializationService->materialize($tokens));
    }

    /**
     * The batch limit is MaterializationService's, not this middleware's: two
     * copies of it drift into a browser that posts fifty tokens and a server
     * that answers 400.
     *
     * @return list<string>|null null when the payload is not a usable batch
     */
    private static function readTokens(mixed $payload): ?array
    {
        $tokens = is_array($payload) ? ($payload['tokens'] ?? null) : null;
        if (!is_array($tokens) || [] === $tokens || count($tokens) > MaterializationService::MAX_TOKENS) {
            return null;
        }

        // This endpoint is public and unauthenticated, so it is handed
        // whatever the caller wrote. An element that is itself an array
        // would reach strval() and raise an "Array to string conversion"
        // warning, which is a 500 on any install that has hardened
        // exceptionalErrors, and would materialize the token "Array".
        $scalars = array_filter($tokens, is_scalar(...));

        return count($scalars) === count($tokens)
            ? array_map(strval(...), array_values($scalars))
            : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');

        return $response->withBody($this->streamFactory->createStream(json_encode($data, \JSON_THROW_ON_ERROR)));
    }
}
