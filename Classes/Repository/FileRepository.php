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

namespace KonradMichalik\Typo3FileSync\Repository;

use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use KonradMichalik\Typo3FileSync\Configuration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\{File, ProcessedFileRepository, StorageRepository};

use function count;

/**
 * FileRepository.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class FileRepository
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private ProcessedFileRepository $processedFileRepository,
        private StorageRepository $storageRepository,
    ) {}

    /**
     * @return array<int, array{count: int, tx_typo3_file_sync_identifier: string}>
     */
    public function countByIdentifier(?int $storage = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $expressionBuilder = $queryBuilder->expr();
        $queryBuilder->getConcreteQueryBuilder()->select('COUNT(*) AS count', Configuration::FIELD_IDENTIFIER);
        $queryBuilder->from('sys_file')
            ->where(
                $expressionBuilder->neq(
                    Configuration::FIELD_IDENTIFIER,
                    $queryBuilder->createNamedParameter(''),
                ),
            )
            ->groupBy(Configuration::FIELD_IDENTIFIER);

        if (null !== $storage) {
            $queryBuilder->andWhere(
                $expressionBuilder->eq(
                    'storage',
                    $queryBuilder->createNamedParameter($storage, ParameterType::INTEGER),
                ),
            );
        }

        /** @var array<int, array{count: int, tx_typo3_file_sync_identifier: string}> $rows */
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return $rows;
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
                    Configuration::FIELD_IDENTIFIER,
                    $queryBuilder->createNamedParameter($identifier),
                ),
            )
            ->groupBy(Configuration::FIELD_IDENTIFIER, 'identifier', 'storage');

        if (null !== $storage) {
            $queryBuilder->andWhere(
                $expressionBuilder->eq(
                    'storage',
                    $queryBuilder->createNamedParameter($storage, ParameterType::INTEGER),
                ),
            );
        }

        /** @var array<int, array{storage: int, identifier: string}> $rows */
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    /**
     * @return array{identifier: string, tstamp: int}
     */
    public function findSyncData(int $fileUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');

        $row = $queryBuilder
            ->select(Configuration::FIELD_IDENTIFIER, Configuration::FIELD_TSTAMP)
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($fileUid, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return ['identifier' => '', 'tstamp' => 0];
        }

        return [
            'identifier' => (string) ($row[Configuration::FIELD_IDENTIFIER] ?? ''),
            'tstamp' => (int) ($row[Configuration::FIELD_TSTAMP] ?? 0),
        ];
    }

    public function updateIdentifier(File $file, string $identifier): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->update('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($file->getUid(), ParameterType::INTEGER),
                ),
            )
            ->set(Configuration::FIELD_IDENTIFIER, $identifier)
            ->set(Configuration::FIELD_TSTAMP, (string) time())
            ->set('missing', 0, true, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function countMissing(int $storageUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $expressionBuilder = $queryBuilder->expr();

        return (int) $queryBuilder->count('*')
            ->from('sys_file')
            ->where(
                $expressionBuilder->eq(
                    'storage',
                    $queryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER),
                ),
                $expressionBuilder->eq(
                    'missing',
                    $queryBuilder->createNamedParameter(1, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchOne();
    }

    public function resetMissing(int $storageUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');

        return $queryBuilder->update('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'storage',
                    $queryBuilder->createNamedParameter($storageUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'missing',
                    $queryBuilder->createNamedParameter(1, ParameterType::INTEGER),
                ),
            )
            ->set('missing', 0, true, ParameterType::INTEGER)
            ->executeStatement();
    }

    public function deleteByIdentifier(string $identifier, ?int $storage = null): int
    {
        $rows = $this->findByIdentifier($identifier, $storage);
        foreach ($rows as $row) {
            try {
                $storageObject = $this->storageRepository->getStorageObject(max(0, $row['storage']));
                $file = $storageObject->getFileByIdentifier($row['identifier']);
                if (!$file instanceof File) {
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
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return count($rows);
    }
}
