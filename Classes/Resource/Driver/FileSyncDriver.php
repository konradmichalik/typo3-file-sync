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

namespace KonradMichalik\Typo3FileSync\Resource\Driver;

use KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollection;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FileSyncDriver extends LocalDriver
{
    protected readonly DriverInterface $originalDriverObject;
    protected readonly RemoteResourceCollection $remoteResourceCollection;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(array $configuration, DriverInterface $originalDriverObject, RemoteResourceCollection $remoteResourceCollection)
    {
        parent::__construct($configuration);

        $this->originalDriverObject = $originalDriverObject;
        $this->remoteResourceCollection = $remoteResourceCollection;
    }

    public function fileExists(string $fileIdentifier): bool
    {
        $this->ensureFileExists($fileIdentifier);

        return $this->originalDriverObject->fileExists($fileIdentifier);
    }

    public function folderExists(string $folderIdentifier): bool
    {
        if (parent::folderExists($folderIdentifier)) {
            return true;
        }

        $folderIdentifier = rtrim($folderIdentifier, '/');
        $pathinfo = pathinfo($folderIdentifier);
        if (!empty($pathinfo['basename']) && !empty($pathinfo['extension'])) {
            $this->ensureFileExists($folderIdentifier);
        }

        return false;
    }

    public function getPublicUrl(string $identifier): ?string
    {
        $this->ensureFileExists($identifier);

        return $this->originalDriverObject->getPublicUrl($identifier);
    }

    public function getFileContents(string $fileIdentifier): string
    {
        $this->ensureFileExists($fileIdentifier);

        return $this->originalDriverObject->getFileContents($fileIdentifier);
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $this->ensureFileExists($fileIdentifier);

        return $this->originalDriverObject->getFileForLocalProcessing($fileIdentifier, $writable);
    }

    /**
     * @param array<int, string> $propertiesToExtract
     * @return array<string, mixed>
     */
    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $this->ensureFileExists($fileIdentifier);

        return $this->originalDriverObject->getFileInfoByIdentifier($fileIdentifier, $propertiesToExtract);
    }

    /**
     * @return array{r: bool, w: bool}
     */
    public function getPermissions(string $identifier): array
    {
        $this->ensureFileExists($identifier);

        return $this->originalDriverObject->getPermissions($identifier);
    }

    public function dumpFileContents(string $identifier): void
    {
        $this->ensureFileExists($identifier);

        $this->originalDriverObject->dumpFileContents($identifier);
    }

    public function isCaseSensitiveFileSystem(): bool
    {
        return true;
    }

    protected function ensureFileExists(string $fileIdentifier): bool
    {
        $absoluteFilePath = $this->getAbsolutePath($fileIdentifier, false);
        if (empty($absoluteFilePath) || file_exists($absoluteFilePath)) {
            return true;
        }

        $fileName = basename($absoluteFilePath);
        if (empty($fileName)) {
            return true;
        }

        $filePath = $this->originalDriverObject->getPublicUrl($fileIdentifier);

        $fileContent = $this->remoteResourceCollection->get($fileIdentifier, $filePath);
        if ($fileContent !== null) {
            $absoluteFilePath = $this->getAbsolutePath($fileIdentifier);
            GeneralUtility::mkdir_deep(dirname($absoluteFilePath));
            file_put_contents($absoluteFilePath, $fileContent);

            if (is_resource($fileContent) && get_resource_type($fileContent) === 'stream') {
                fclose($fileContent);
            }
        }

        return true;
    }

    protected function getAbsolutePath(string $fileIdentifier, bool $callOriginalDriver = true): string
    {
        $relativeFilePath = ltrim($this->canonicalizeAndCheckFileIdentifier($fileIdentifier, $callOriginalDriver), '/');

        return $this->absoluteBasePath . $relativeFilePath;
    }

    protected function canonicalizeAndCheckFileIdentifier(string $fileIdentifier, bool $callOriginalDriver = true): string
    {
        return $callOriginalDriver
            ? $this->originalDriverObject->canonicalizeAndCheckFileIdentifier($fileIdentifier)
            : parent::canonicalizeAndCheckFileIdentifier($fileIdentifier);
    }
}
