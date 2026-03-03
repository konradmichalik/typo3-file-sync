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

namespace KonradMichalik\Typo3FileSync\Resource;

use Doctrine\DBAL\ParameterType;
use KonradMichalik\Typo3FileSync\Exception\MissingInterfaceException;
use KonradMichalik\Typo3FileSync\Exception\UnknownResourceException;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteResourceCollection implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var array<string, FileInterface|null>
     */
    protected static array $fileIdentifierCache = [];

    /**
     * @param array<int, array{identifier: string, handler: RemoteResourceInterface}> $resources
     */
    public function __construct(
        protected readonly array $resources,
        protected readonly StorageRepository $storageRepository,
        protected readonly ResourceFactory $resourceFactory,
        protected readonly FileRepository $fileRepository,
    ) {}

    /**
     * @return resource|string|null
     */
    public function get(string $fileIdentifier, string $filePath): mixed
    {
        if ($this->fileCanBeReProcessed($fileIdentifier, $filePath) || static::$fileIdentifierCache[$filePath] === null) {
            return null;
        }

        $this->logger->debug(
            sprintf('Fetching file "%s" (%s)', $filePath, $fileIdentifier),
            array_map(static fn (array $resource): string => $resource['identifier'], $this->resources)
        );

        foreach ($this->resources as $resource) {
            if (!$resource['handler'] instanceof RemoteResourceInterface) {
                throw new MissingInterfaceException(
                    'Remote resource of type ' . get_class($resource['handler']) . ' doesn\'t implement ' . RemoteResourceInterface::class,
                    1519680070
                );
            }

            $file = static::$fileIdentifierCache[$filePath];
            if ($resource['handler']->hasFile($fileIdentifier, $filePath, $file)) {
                $fileContent = $resource['handler']->getFile($fileIdentifier, $filePath, $file);
                if ($fileContent === false) {
                    $this->logger->debug(
                        sprintf('Resource "%s" returned empty content', $resource['identifier']),
                        [
                            'fileIdentifier' => $fileIdentifier,
                            'filePath' => $filePath,
                        ]
                    );
                    continue;
                }
                if (is_resource($fileContent) && get_resource_type($fileContent) !== 'stream') {
                    throw new UnknownResourceException(
                        'Cannot handle resource type "' . get_resource_type($fileContent) . '" as file content',
                        1583421958
                    );
                }

                $this->fileRepository->updateIdentifier($file, $resource['identifier']);
                $this->logger->debug(
                    sprintf('Resource "%s" found file', $resource['identifier']),
                    [
                        'fileIdentifier' => $fileIdentifier,
                        'filePath' => $filePath,
                    ]
                );

                return $fileContent;
            }
            $this->logger->debug(
                sprintf('Resource "%s" couldn\'t handle file', $resource['identifier']),
                [
                    'fileIdentifier' => $fileIdentifier,
                    'filePath' => $filePath,
                ]
            );
        }

        return null;
    }

    protected function fileCanBeReProcessed(string $fileIdentifier, string $filePath): bool
    {
        if (!array_key_exists($filePath, static::$fileIdentifierCache)) {
            static::$fileIdentifierCache[$filePath] = null;
            $localPath = $filePath;
            $storage = $this->storageRepository->getStorageObject(0, [], $localPath);
            if ($storage->getUid() !== 0) {
                static::$fileIdentifierCache[$filePath] = $this->getFileObjectFromStorage($storage, $fileIdentifier);
            }
        }

        return static::$fileIdentifierCache[$filePath] instanceof ProcessedFile
            && static::$fileIdentifierCache[$filePath]->getOriginalFile()->exists();
    }

    protected function getFileObjectFromStorage(ResourceStorage $storage, string $fileIdentifier): ?FileInterface
    {
        if (!$storage->isWithinProcessingFolder($fileIdentifier)) {
            try {
                $fileObject = $storage->getFileByIdentifier($fileIdentifier);
            } catch (\InvalidArgumentException) {
                return null;
            }
        } else {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_processedfile');
            $expressionBuilder = $queryBuilder->expr();
            $databaseRow = $queryBuilder->select('*')
                ->from('sys_file_processedfile')
                ->where(
                    $expressionBuilder->eq(
                        'storage',
                        $queryBuilder->createNamedParameter($storage->getUid(), ParameterType::INTEGER)
                    ),
                    $expressionBuilder->eq(
                        'identifier',
                        $queryBuilder->createNamedParameter($fileIdentifier)
                    )
                )
                ->executeQuery()
                ->fetchAssociative();
            if (empty($databaseRow)) {
                return null;
            }

            $originalFile = $this->resourceFactory->getFileObject((int)$databaseRow['original']);
            $taskType = $databaseRow['task_type'];
            $configuration = unserialize($databaseRow['configuration'], ['allowed_classes' => false]);

            $fileObject = GeneralUtility::makeInstance(
                ProcessedFile::class,
                $originalFile,
                $taskType,
                $configuration,
                $databaseRow
            );
        }

        return $fileObject;
    }
}
