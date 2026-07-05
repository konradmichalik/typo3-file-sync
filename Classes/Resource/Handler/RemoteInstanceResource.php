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

use GuzzleHttp\{ClientInterface, RequestOptions};
use GuzzleHttp\Exception\TransferException;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_array;
use function is_resource;
use function sprintf;

/**
 * RemoteInstanceResource.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RemoteInstanceResource implements LoggerAwareInterface, RemoteResourceInterface
{
    use LoggerAwareTrait;

    private readonly ClientInterface $httpClient;
    private readonly string $url;
    /** @var array<string, mixed> */
    private array $requestOptions;

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

        $this->requestOptions = isset($urlParts['user'], $urlParts['pass'])
            ? ['auth' => [$urlParts['user'], $urlParts['pass']]]
            : [];
    }

    /**
     * @return resource|false
     */
    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): mixed
    {
        $url = $this->url.ltrim($filePath, '/');

        // Spool the response into php://temp instead of loading it into a
        // string: small files stay in memory, large ones spill to disk
        // rather than exhausting the memory limit.
        $target = fopen('php://temp', 'w+');
        if (false === $target) {
            return false;
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                $url,
                [RequestOptions::SINK => $target] + $this->requestOptions,
            );
            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                $this->logger?->debug(
                    sprintf('GET %s returned HTTP %d', $url, $statusCode),
                );
                $this->close($target);

                return false;
            }

            rewind($target);

            return $target;
        } catch (TransferException $e) {
            $this->logger?->warning(
                sprintf('GET %s failed: %s', $url, $e->getMessage()),
            );
            $this->close($target);

            return false;
        }
    }

    /**
     * @param resource $stream
     */
    private function close(mixed $stream): void
    {
        if (is_resource($stream)) {
            fclose($stream);
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
