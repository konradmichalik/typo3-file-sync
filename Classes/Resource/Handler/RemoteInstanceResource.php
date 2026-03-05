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

use GuzzleHttp\Exception\TransferException;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_array;
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

    private readonly RequestFactory $requestFactory;
    private readonly string $url;
    /** @var array<string, mixed> */
    private array $requestOptions;

    /**
     * @param array<string, mixed>|string|null $configuration
     */
    public function __construct(array|string|null $configuration, ?RequestFactory $requestFactory = null)
    {
        $this->requestFactory = $requestFactory ?? GeneralUtility::makeInstance(RequestFactory::class);

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

    public function hasFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): bool
    {
        $url = $this->url.ltrim($filePath, '/');
        try {
            $response = $this->requestFactory->request($url, 'HEAD', $this->requestOptions);
            $statusCode = $response->getStatusCode();

            if (200 !== $statusCode) {
                $this->logger?->debug(
                    sprintf('HEAD %s returned HTTP %d', $url, $statusCode),
                );

                return false;
            }

            return true;
        } catch (TransferException $e) {
            $this->logger?->warning(
                sprintf('HEAD %s failed: %s', $url, $e->getMessage()),
            );

            return false;
        }
    }

    /**
     * @return string|false
     */
    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): mixed
    {
        $url = $this->url.ltrim($filePath, '/');
        try {
            $response = $this->requestFactory->request($url, 'GET', $this->requestOptions);

            return $response->getBody()->getContents();
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
