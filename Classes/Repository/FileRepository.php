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

namespace KonradMichalik\Typo3FileSync\Repository;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class FileRepository
{
    public function __construct(
        protected readonly ConnectionPool $connectionPool,
        protected readonly ProcessedFileRepository $processedFileRepository,
        protected readonly StorageRepository $storageRepository,
    ) {}

    /**
     * @return array<int, array{count: int, tx_typo3_file_sync_identifier: string}>
     */
    public function countByIdentifier(?int $storage = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $expressionBuilder = $queryBuilder->expr();
        $queryBuilder->getConcreteQueryBuilder()->select('COUNT(*) AS count', 'tx_typo3_file_sync_identifier');
        $queryBuilder->from('sys_file')
            ->where(
                $expressionBuilder->neq(
                    'tx_typo3_file_sync_identifier',
                    $queryBuilder->createNamedParameter('')
                )
            )
            ->groupBy('tx_typo3_file_sync_identifier');

        if ($storage !== null) {
            $queryBuilder->andWhere(
                $expressionBuilder->eq(
                    'storage',
                    $queryBuilder->createNamedParameter($storage, ParameterType::INTEGER)
                )
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<int, array{storage: int, identifier: string}>
     */
    public function findByIdentifier(string $identifier, ?int $storage = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $expressionBuilder = $queryBuilder->expr();
        $queryBuilder->select('storage', 'identifier')
            ->from('sys_file')
            ->where(
                $expressionBuilder->eq(
                    'tx_typo3_file_sync_identifier',
                    $queryBuilder->createNamedParameter($identifier)
                )
            )
            ->groupBy('tx_typo3_file_sync_identifier', 'identifier', 'storage');

        if ($storage !== null) {
            $queryBuilder->andWhere(
                $expressionBuilder->eq(
                    'storage',
                    $queryBuilder->createNamedParameter($storage, ParameterType::INTEGER)
                )
            );
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    public function updateIdentifier(FileInterface $file, string $identifier): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->update('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($file->getUid(), ParameterType::INTEGER)
                )
            )
            ->set('tx_typo3_file_sync_identifier', $identifier)
            ->executeStatement();
    }

    public function deleteByIdentifier(string $identifier, ?int $storage = null): int
    {
        $rows = $this->findByIdentifier($identifier, $storage);
        foreach ($rows as $row) {
            try {
                $storage = $this->storageRepository->getStorageObject((int)$row['storage']);
                $file = $storage->getFileByIdentifier($row['identifier']);
                if (!$file) {
                    continue;
                }

                foreach ($this->processedFileRepository->findAllByOriginalFile($file) as $processedFile) {
                    if ($processedFile->exists()) {
                        $processedFile->delete(true);
                    }
                }

                $absolutePath = $file->getForLocalProcessing(false);
                if (@unlink($absolutePath)) {
                    $this->updateIdentifier($file, '');
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return count($rows);
    }
}
