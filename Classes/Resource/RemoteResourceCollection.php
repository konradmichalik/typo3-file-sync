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

namespace KonradMichalik\Typo3FileSync\Resource;

use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use KonradMichalik\Typo3FileSync\Exception\UnknownResourceException;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\{AbstractFile, File, ProcessedFile, ResourceFactory, ResourceStorage, StorageRepository};
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_key_exists;
use function is_resource;
use function sprintf;

/**
 * RemoteResourceCollection.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RemoteResourceCollection implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var array<string, AbstractFile|null>
     */
    protected array $fileIdentifierCache = [];

    /**
     * @param array<int, array{identifier: string, handler: RemoteResourceInterface}> $resources
     */
    public function __construct(
        protected readonly array $resources,
        protected readonly StorageRepository $storageRepository,
        protected readonly ResourceFactory $resourceFactory,
        protected readonly FileRepository $fileRepository,
        protected readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return resource|string|null
     */
    public function get(string $fileIdentifier, string $filePath): mixed
    {
        $this->resolveFileObject($fileIdentifier, $filePath);
        if (null === $this->fileIdentifierCache[$filePath]) {
            return null;
        }

        $this->logger?->debug(
            sprintf('Fetching file "%s" (%s)', $filePath, $fileIdentifier),
            array_map(static fn (array $resource): string => $resource['identifier'], $this->resources),
        );

        foreach ($this->resources as $resource) {
            $file = $this->fileIdentifierCache[$filePath];
            $fileContent = $resource['handler']->getFile($fileIdentifier, $filePath, $file);
            if (false === $fileContent) {
                $this->logger?->debug(
                    sprintf('Resource "%s" couldn\'t handle file', $resource['identifier']),
                    [
                        'fileIdentifier' => $fileIdentifier,
                        'filePath' => $filePath,
                    ],
                );
                continue;
            }
            if (is_resource($fileContent) && 'stream' !== get_resource_type($fileContent)) {
                throw new UnknownResourceException('Cannot handle resource type "'.get_resource_type($fileContent).'" as file content', 1583421958);
            }

            if ($file instanceof File) {
                $this->fileRepository->updateIdentifier($file, $resource['identifier']);
            }
            $this->logger?->debug(
                sprintf('Resource "%s" found file', $resource['identifier']),
                [
                    'fileIdentifier' => $fileIdentifier,
                    'filePath' => $filePath,
                ],
            );

            return $fileContent;
        }

        return null;
    }

    protected function resolveFileObject(string $fileIdentifier, string $filePath): void
    {
        if (array_key_exists($filePath, $this->fileIdentifierCache)) {
            return;
        }

        $this->fileIdentifierCache[$filePath] = null;
        $localPath = '' !== $filePath ? $filePath : null;
        $storage = $this->storageRepository->getStorageObject(0, [], $localPath);
        if (0 !== $storage->getUid()) {
            $this->fileIdentifierCache[$filePath] = $this->getFileObjectFromStorage($storage, $fileIdentifier);
        }
    }

    protected function getFileObjectFromStorage(ResourceStorage $storage, string $fileIdentifier): ?AbstractFile
    {
        if (!$storage->isWithinProcessingFolder($fileIdentifier)) {
            try {
                $fileObject = $storage->getFileByIdentifier($fileIdentifier);
            } catch (InvalidArgumentException) {
                return null;
            }
        } else {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
            $expressionBuilder = $queryBuilder->expr();
            $databaseRow = $queryBuilder->select('*')
                ->from('sys_file_processedfile')
                ->where(
                    $expressionBuilder->eq(
                        'storage',
                        $queryBuilder->createNamedParameter($storage->getUid(), ParameterType::INTEGER),
                    ),
                    $expressionBuilder->eq(
                        'identifier',
                        $queryBuilder->createNamedParameter($fileIdentifier),
                    ),
                )
                ->executeQuery()
                ->fetchAssociative();
            if (false === $databaseRow) {
                return null;
            }

            $originalFile = $this->resourceFactory->getFileObject((int) $databaseRow['original']);
            $taskType = $databaseRow['task_type'];
            $configuration = unserialize($databaseRow['configuration'], ['allowed_classes' => false]);

            $fileObject = GeneralUtility::makeInstance(
                ProcessedFile::class,
                $originalFile,
                $taskType,
                $configuration,
                $databaseRow,
            );
        }

        return $fileObject;
    }
}
