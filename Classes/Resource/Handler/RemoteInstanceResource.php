<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_file_sync" TYPO3 CMS extension.
 *
 * (c) 2025 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3FileSync\Resource\Handler;

use GuzzleHttp\Exception\TransferException;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteInstanceResource implements RemoteResourceInterface
{
    protected readonly RequestFactory $requestFactory;
    protected readonly string $url;
    /** @var array<string, mixed> */
    protected readonly array $requestOptions;

    public function __construct(array|string|null $configuration, ?RequestFactory $requestFactory = null)
    {
        $this->requestFactory = $requestFactory ?? GeneralUtility::makeInstance(RequestFactory::class);

        $baseUrl = is_array($configuration) ? ($configuration['url'] ?? '') : (string)$configuration;
        $baseUrl = self::resolveEnvPlaceholders($baseUrl);
        $urlParts = parse_url($baseUrl);
        $urlParts['scheme'] = $urlParts['scheme'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'https');
        $this->url = rtrim($this->buildUrl($urlParts), '/') . '/';

        $this->requestOptions = isset($urlParts['user'], $urlParts['pass'])
            ? ['auth' => [$urlParts['user'], $urlParts['pass']]]
            : [];
    }

    public function hasFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): bool
    {
        try {
            $response = $this->requestFactory->request($this->url . ltrim($filePath, '/'), 'HEAD', $this->requestOptions);

            return $response->getStatusCode() === 200;
        } catch (TransferException) {
            return false;
        }
    }

    /**
     * @return string|false
     */
    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): string|false
    {
        try {
            $response = $this->requestFactory->request($this->url . ltrim($filePath, '/'), 'GET', $this->requestOptions);

            return $response->getBody()->getContents();
        } catch (TransferException) {
            return false;
        }
    }

    /**
     * @param array<string, string|int> $urlParts
     */
    private static function resolveEnvPlaceholders(string $value): string
    {
        return preg_replace_callback('/%env\(([^)]+)\)%/', static function (array $matches): string {
            return getenv($matches[1]) ?: '';
        }, $value);
    }

    private function buildUrl(array $urlParts): string
    {
        $scheme = isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '';
        $host = $urlParts['host'] ?? '';
        $port = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $path = $urlParts['path'] ?? '';

        return $scheme . $host . $port . $path;
    }
}
