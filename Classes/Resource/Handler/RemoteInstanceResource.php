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

    public function __construct(array|string|null $configuration, ?RequestFactory $requestFactory = null)
    {
        $this->requestFactory = $requestFactory ?? GeneralUtility::makeInstance(RequestFactory::class);

        $baseUrl = is_array($configuration) ? ($configuration['url'] ?? '') : (string)$configuration;
        $urlParts = parse_url($baseUrl);
        $urlParts['scheme'] = $urlParts['scheme'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'https');
        $this->url = rtrim($this->buildUrl($urlParts), '/') . '/';
    }

    public function hasFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): bool
    {
        try {
            $response = $this->requestFactory->request($this->url . ltrim($filePath, '/'), 'HEAD');

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
            $response = $this->requestFactory->request($this->url . ltrim($filePath, '/'));

            return $response->getBody()->getContents();
        } catch (TransferException) {
            return false;
        }
    }

    /**
     * @param array<string, string|int> $urlParts
     */
    private function buildUrl(array $urlParts): string
    {
        $scheme = isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '';
        $host = $urlParts['host'] ?? '';
        $port = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $path = $urlParts['path'] ?? '';

        return $scheme . $host . $port . $path;
    }
}
