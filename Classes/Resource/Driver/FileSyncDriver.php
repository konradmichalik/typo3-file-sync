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

namespace KonradMichalik\Typo3FileSync\Resource\Driver;

use KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollection;
use TYPO3\CMS\Core\Resource\Driver\{DriverInterface, LocalDriver};
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;
use function is_resource;

/**
 * FileSyncDriver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FileSyncDriver extends LocalDriver
{
    protected readonly DriverInterface $originalDriverObject;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(array $configuration, DriverInterface $originalDriverObject, protected readonly RemoteResourceCollection $remoteResourceCollection)
    {
        parent::__construct($configuration);

        $this->originalDriverObject = $originalDriverObject;
    }

    public function fileExists(string $fileIdentifier): bool
    {
        $this->ensureFileExists($fileIdentifier);

        return $this->originalDriverObject->fileExists($fileIdentifier);
    }

    public function folderExists(string $folderIdentifier): bool
    {
        if ($this->originalDriverObject->folderExists($folderIdentifier)) {
            return true;
        }

        $folderIdentifier = rtrim($folderIdentifier, '/');
        $pathinfo = pathinfo($folderIdentifier);
        if ('' !== $pathinfo['basename'] && isset($pathinfo['extension']) && '' !== $pathinfo['extension']) {
            $this->ensureFileExists($folderIdentifier);
        }

        return false;
    }

    /**
     * @param non-empty-string $identifier
     *
     * @return non-empty-string|null
     */
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
     * @param list<string> $propertiesToExtract
     *
     * @return array<non-empty-string, mixed>
     */
    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $this->ensureFileExists($fileIdentifier);

        /** @var array<non-empty-string, mixed> $result */
        $result = $this->originalDriverObject->getFileInfoByIdentifier($fileIdentifier, $propertiesToExtract);

        return $result;
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
        if ('' === $absoluteFilePath || file_exists($absoluteFilePath)) {
            return true;
        }

        $fileName = basename($absoluteFilePath);
        if ('' === $fileName) {
            return true;
        }

        $filePath = '' !== $fileIdentifier ? $this->originalDriverObject->getPublicUrl($fileIdentifier) : null;

        $fileContent = $this->remoteResourceCollection->get($fileIdentifier, $filePath ?? '');
        if (null !== $fileContent) {
            $absoluteFilePath = $this->getAbsolutePath($fileIdentifier);
            GeneralUtility::mkdir_deep(dirname($absoluteFilePath));
            file_put_contents($absoluteFilePath, $fileContent);

            if (is_resource($fileContent) && 'stream' === get_resource_type($fileContent)) {
                fclose($fileContent);
            }
        }

        return true;
    }

    protected function getAbsolutePath(string $fileIdentifier, bool $callOriginalDriver = true): string
    {
        $relativeFilePath = ltrim($this->canonicalizeAndCheckFileIdentifier($fileIdentifier, $callOriginalDriver), '/');

        return $this->absoluteBasePath.$relativeFilePath;
    }

    protected function canonicalizeAndCheckFileIdentifier(string $fileIdentifier, bool $callOriginalDriver = true): string
    {
        if ($callOriginalDriver && $this->originalDriverObject instanceof LocalDriver) {
            return $this->originalDriverObject->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        }

        return parent::canonicalizeAndCheckFileIdentifier($fileIdentifier);
    }
}
