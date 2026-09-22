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
use KonradMichalik\Typo3FileSync\Service\{MaterializationService, MaterializeRateLimiter, PreviewService, SitePath};
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface, StreamFactoryInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Configuration\Features;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function is_array;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function strtolower;
use function strval;
use function trim;

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
        private PreviewService $previewService,
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

        // The caller's own budget, spent before the envelope is looked at, so
        // that a flood cannot dodge the limit by using a verb or a media type
        // that would be rejected cheaply. What it burns is nobody else's.
        if (!$this->rateLimiter->isAddressAccepted($request)) {
            return $this->json(['error' => 'too many requests'], 429);
        }

        $refusal = $this->refuseByEnvelope($request);
        if (null !== $refusal) {
            return $refusal;
        }

        // The site's budget, spent only on a request this endpoint would have
        // served. The same deterrent cannot hold here, because the caller who
        // pays is not the caller who floods: a third-party page can make its
        // own visitors emit cross-origin preflights at this path, and each of
        // those is an OPTIONS the refusal above already answered. Counting
        // them here would let a request that can never succeed deny deferred
        // loading and previews to the whole site.
        if (!$this->rateLimiter->isSiteAccepted()) {
            return $this->json(['error' => 'too many requests'], 429);
        }

        $payload = json_decode((string) $request->getBody(), true);
        $tokens = self::readTokens($payload);
        if (null === $tokens) {
            return $this->json(['error' => 'bad request'], 400);
        }

        // Routed last, behind every guard above: the preview stage is the
        // cheaper half of this endpoint, not a way around its rate limit or
        // its method check.
        if ('preview' === self::readStage($payload)) {
            if (!$this->features->isFeatureEnabled(Configuration::FEATURE_PREVIEW_IMAGES)) {
                return $this->json(['error' => 'disabled'], 404);
            }

            return $this->json($this->previewService->preview($tokens));
        }

        return $this->json($this->materializationService->materialize($tokens));
    }

    /**
     * Every refusal that the request envelope decides on its own: the method
     * line and the headers settle all three, no body is read, no storage is
     * touched, nothing is spent. They belong together because that is exactly
     * the set the site-wide limiter has to sit behind. Traffic this endpoint
     * would never serve must not be able to spend the budget of traffic it
     * would, and the boundary between free to refuse and paid for by the site
     * is what this method names.
     */
    private function refuseByEnvelope(ServerRequestInterface $request): ?ResponseInterface
    {
        if ('POST' !== $request->getMethod()) {
            return $this->json(['error' => 'method not allowed'], 405);
        }

        if (!self::declaresJson($request)) {
            return $this->json(['error' => 'unsupported media type'], 415);
        }

        if (self::isCrossSite($request)) {
            return $this->json(['error' => 'forbidden'], 403);
        }

        return null;
    }

    /**
     * Without this, any page on any host can drive this endpoint from its
     * own visitors. text/plain, multipart/form-data and
     * application/x-www-form-urlencoded are CORS-safelisted, so a
     * cross-origin fetch() carrying one of them is delivered and processed
     * in full; the attacker never sees the response and does not need to,
     * because the side effects are the point and the tokens are public in
     * the site's own HTML. application/json is not safelisted, so demanding
     * it is what forces the preflight such a call cannot answer. The
     * extension's own module already sends it, so nothing legitimate
     * changes.
     *
     * The parameters are dropped before comparing, because
     * "application/json; charset=utf-8" is the same media type.
     */
    private static function declaresJson(ServerRequestInterface $request): bool
    {
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

        return 'application/json' === $mediaType;
    }

    /**
     * A second layer rather than a replacement for the one above. Every
     * current browser sends Sec-Fetch-Site on a fetch(), and a same-origin
     * POST is the only shape this endpoint has; but the header is absent on
     * older browsers and on every non-browser caller, so an absent one
     * cannot be read as a refusal without breaking them.
     *
     * "none" is refused on purpose, along with "cross-site" and "same-site".
     * A browser sends it for a user-initiated navigation, from the address
     * bar or a bookmark, which is a GET and never reaches here because the
     * method check answers it first. So the value can only arrive on a
     * request no browser produces, and refusing it costs nothing legitimate.
     * It is not an oversight to be turned into an allow.
     */
    private static function isCrossSite(ServerRequestInterface $request): bool
    {
        $site = $request->getHeaderLine('Sec-Fetch-Site');

        return '' !== $site && 'same-origin' !== $site;
    }

    /**
     * An unknown stage is the original one, so a browser still running a
     * cached copy of the previous version of the module gets the real file
     * rather than an error.
     */
    private static function readStage(mixed $payload): string
    {
        $stage = is_array($payload) ? ($payload['stage'] ?? null) : null;

        return is_string($stage) ? $stage : 'original';
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
