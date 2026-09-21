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

namespace KonradMichalik\Typo3FileSync\Resource\Handler;

use Generator;
use GuzzleHttp\{ClientInterface, Pool, RequestOptions};
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use KonradMichalik\Typo3FileSync\Resource\{BatchRemoteResourceInterface, DeferrableResourceInterface, RemoteResourceInterface};
use Psr\Http\Message\ResponseInterface;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function is_array;
use function is_resource;
use function ltrim;
use function sprintf;

/**
 * RemoteInstanceResource.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RemoteInstanceResource implements BatchRemoteResourceInterface, DeferrableResourceInterface, LoggerAwareInterface, RemoteResourceInterface
{
    use LoggerAwareTrait;

    /**
     * Explicit timeouts: fetching runs synchronously within the rendering
     * process and must never block indefinitely (TYPO3's HTTP default
     * timeout is 0 = unlimited).
     */
    private const DEFAULT_CONNECT_TIMEOUT = 5;
    private const DEFAULT_TIMEOUT = 15;
    private const DEFAULT_CONCURRENCY = 8;

    private readonly ClientInterface $httpClient;
    private readonly string $url;
    /** @var array<string, mixed> */
    private array $requestOptions;

    /**
     * @var array<string, resource>
     */
    private array $prefetched = [];

    /**
     * @param array<string, mixed>|string|null $configuration
     */
    public function __construct(array|string|null $configuration, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? GeneralUtility::makeInstance(GuzzleClientFactory::class)->getClient();

        $baseUrl = is_array($configuration) ? ($configuration['url'] ?? '') : (string) $configuration;
        $baseUrl = self::resolveEnvPlaceholders($baseUrl);
        $urlParts = parse_url($baseUrl);
        if (!is_array($urlParts)) {
            $urlParts = [];
        }
        $urlParts['scheme'] ??= 'https';
        $this->url = rtrim($this->buildUrl($urlParts), '/').'/';

        $options = is_array($configuration) ? $configuration : [];
        $this->requestOptions = [
            RequestOptions::CONNECT_TIMEOUT => (float) ($options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT),
            RequestOptions::TIMEOUT => (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT),
        ];
        if (isset($urlParts['user'], $urlParts['pass'])) {
            $this->requestOptions[RequestOptions::AUTH] = [$urlParts['user'], $urlParts['pass']];
        }
    }

    public function __destruct()
    {
        // Deferred loading means some prefetched files are never emitted via
        // getFile(): a leftover entry here is the expected steady state, not
        // an error case, and nothing else owns these detached resources.
        foreach ($this->prefetched as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $this->prefetched = [];
    }

    /**
     * @param list<string> $filePaths
     */
    public function prefetch(array $filePaths): void
    {
        // Normalized once, here: the buffer is keyed and later looked up
        // (in getFile()) by the same leading-slash-stripped value, so a
        // caller mixing '/fileadmin/x.jpg' and 'fileadmin/x.jpg' must not
        // end up with two requests for one file and an unreachable buffer
        // entry.
        $filePaths = array_values(array_unique(array_filter(
            array_map(static fn (string $path): string => ltrim($path, '/'), $filePaths),
            static fn (string $path): bool => '' !== $path,
        )));
        if ([] === $filePaths) {
            return;
        }

        $requests = function () use ($filePaths): Generator {
            foreach ($filePaths as $filePath) {
                yield $filePath => new Request('GET', $this->url.$filePath);
            }
        };

        try {
            $pool = new Pool($this->httpClient, $requests(), [
                'concurrency' => self::DEFAULT_CONCURRENCY,
                'options' => $this->requestOptions,
                'fulfilled' => function (ResponseInterface $response, string $filePath): void {
                    if (200 !== $response->getStatusCode()) {
                        return;
                    }

                    // Same detach()-not-SINK reasoning as getFile(): keep the
                    // resource alive past Guzzle's own objects being collected.
                    $stream = $response->getBody()->detach();
                    if (is_resource($stream)) {
                        rewind($stream);
                        $this->prefetched[$filePath] = $stream;
                    }
                },
                'rejected' => function (mixed $reason, string $filePath): void {
                    $this->logger?->warning(
                        sprintf('Prefetch of %s failed', $filePath),
                    );
                },
            ]);

            $pool->promise()->wait();
        } catch (Throwable $e) {
            // A single unparseable path (e.g. a MalformedUriException from a
            // Request that can never be built) must not fail the whole
            // batch: this feature exists to keep image loading from
            // breaking a page render, not to add a new way to break it.
            // Entries already buffered by earlier fulfilled requests stay
            // valid.
            $this->logger?->warning(
                sprintf('Prefetch batch failed: %s', $e->getMessage()),
            );
        }
    }

    /**
     * @return resource|false
     */
    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): mixed
    {
        $normalizedPath = ltrim($filePath, '/');
        $buffered = $this->prefetched[$normalizedPath] ?? null;
        // Removed unconditionally, not just when a resource is found: a
        // caller may already have read or closed this handle, and rewind()
        // on a closed resource is fatal, so it is never rewound and reused.
        // A second request for the same path goes over the wire again.
        unset($this->prefetched[$normalizedPath]);
        if (is_resource($buffered)) {
            return $buffered;
        }

        $url = $this->url.$normalizedPath;

        try {
            // Guzzle spools the response body into its own php://temp stream
            // by default: small files stay in memory, large ones spill to
            // disk rather than exhausting the memory limit.
            $response = $this->httpClient->request('GET', $url, $this->requestOptions);
            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                $this->logger?->debug(
                    sprintf('GET %s returned HTTP %d', $url, $statusCode),
                );

                return false;
            }

            // detach() hands over the underlying resource without Guzzle's
            // Psr7\Stream keeping ownership of it. Passing our own resource
            // via RequestOptions::SINK instead would let Guzzle wrap it in a
            // Stream whose __destruct() closes it as soon as Guzzle's
            // internal objects are garbage collected, which can happen
            // before this method returns.
            $target = $response->getBody()->detach();
            if (!is_resource($target)) {
                // A detached-but-already-closed resource means something else
                // took ownership of the stream. Fail soft instead of letting a
                // TypeError escape from rewind().
                $this->logger?->warning(
                    sprintf('GET %s returned a closed response body', $url),
                );

                return false;
            }

            rewind($target);

            return $target;
        } catch (TransferException $e) {
            $this->logger?->warning(
                sprintf('GET %s failed: %s', $url, $e->getMessage()),
            );

            return false;
        }
    }

    private static function resolveEnvPlaceholders(string $value): string
    {
        return preg_replace_callback('/%env\(([^)]+)\)%/', static fn (array $matches): string => getenv($matches[1]) ?: '', $value) ?? $value;
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, path?: string} $urlParts
     */
    private function buildUrl(array $urlParts): string
    {
        $scheme = isset($urlParts['scheme']) ? $urlParts['scheme'].'://' : '';
        $host = $urlParts['host'] ?? '';
        $port = isset($urlParts['port']) ? ':'.$urlParts['port'] : '';
        $path = $urlParts['path'] ?? '';

        return $scheme.$host.$port.$path;
    }
}
