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
use KonradMichalik\Typo3FileSync\Service\MaterializationService;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Configuration\Features;

use function array_map;
use function array_values;
use function count;
use function is_array;
use function json_decode;
use function json_encode;
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

    private const MAX_TOKENS = 50;

    public function __construct(
        private Features $features,
        private MaterializationService $materializationService,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::PATH !== $request->getUri()->getPath()) {
            return $handler->handle($request);
        }

        if (!$this->features->isFeatureEnabled(Configuration::FEATURE_DEFERRED_LOADING)) {
            return $this->json(['error' => 'disabled'], 404);
        }

        if ('POST' !== $request->getMethod()) {
            return $this->json(['error' => 'method not allowed'], 405);
        }

        $payload = json_decode((string) $request->getBody(), true);
        $tokens = is_array($payload) ? ($payload['tokens'] ?? null) : null;
        if (!is_array($tokens) || [] === $tokens || count($tokens) > self::MAX_TOKENS) {
            return $this->json(['error' => 'bad request'], 400);
        }

        return $this->json($this->materializationService->materialize(array_map(strval(...), array_values($tokens))));
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
