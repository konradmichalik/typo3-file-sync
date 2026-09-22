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

use Doctrine\DBAL\{ArrayParameterType, ParameterType};
use InvalidArgumentException;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\{File, ProcessedFileRepository, StorageRepository};

use function count;
use function is_file;
use function unlink;

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
                if (is_file($absolutePath) && unlink($absolutePath)) {
                    $this->updateIdentifier($file, '');
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return count($rows);
    }

    /**
     * Batched sibling of findSyncData() for a whole materialization request.
     *
     * @param list<int> $fileUids
     *
     * @return array<int, array{identifier: string, tstamp: int}>
     */
    public function findSyncDataByUids(array $fileUids): array
    {
        if ([] === $fileUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $rows = $queryBuilder
            ->select('uid', Configuration::FIELD_IDENTIFIER, Configuration::FIELD_TSTAMP)
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($fileUids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['uid']] = [
                'identifier' => (string) ($row[Configuration::FIELD_IDENTIFIER] ?? ''),
                'tstamp' => (int) ($row[Configuration::FIELD_TSTAMP] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param list<int> $processedFileUids
     *
     * @return array<int, array<string, mixed>>
     */
    public function findProcessedFilesByUids(array $processedFileUids): array
    {
        if ([] === $processedFileUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $rows = $queryBuilder
            ->select('uid', 'original', 'task_type', 'configuration')
            ->from('sys_file_processedfile')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($processedFileUids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['uid']] = $row;
        }

        return $result;
    }

    /**
     * Stamps the sync timestamp without touching the identifier, which is
     * still whatever the fallback chain last made it. Arms the damping
     * window that keeps a file the remote cannot deliver from being
     * retried on every page view.
     */
    public function touchSyncTimestamp(int $fileUid): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->update('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($fileUid, ParameterType::INTEGER),
                ),
            )
            ->set(Configuration::FIELD_TSTAMP, time(), true, ParameterType::INTEGER)
            ->executeStatement();
    }

    /**
     * @param list<int> $storageUids
     */
    public function countProvisional(array $storageUids): int
    {
        if ([] === $storageUids) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $expressionBuilder = $queryBuilder->expr();

        return (int) $queryBuilder->count('*')
            ->from('sys_file')
            ->where(
                $expressionBuilder->in(
                    'storage',
                    $queryBuilder->createNamedParameter($storageUids, ArrayParameterType::INTEGER),
                ),
                $expressionBuilder->neq(
                    Configuration::FIELD_IDENTIFIER,
                    $queryBuilder->createNamedParameter(''),
                ),
                $expressionBuilder->neq(
                    Configuration::FIELD_IDENTIFIER,
                    $queryBuilder->createNamedParameter(ResourceIdentifier::RemoteInstance->value),
                ),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @param list<int>    $storageUids
     * @param list<string> $identifiers
     *
     * @return array<string, int>
     */
    public function findProvisionalProcessedFiles(array $storageUids, array $identifiers): array
    {
        if ([] === $storageUids || [] === $identifiers) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $expressionBuilder = $queryBuilder->expr();
        $rows = $queryBuilder
            ->select('p.uid', 'p.identifier')
            ->from('sys_file_processedfile', 'p')
            ->innerJoin('p', 'sys_file', 'f', $expressionBuilder->eq('f.uid', 'p.original'))
            ->where(
                $expressionBuilder->in(
                    'p.storage',
                    $queryBuilder->createNamedParameter($storageUids, ArrayParameterType::INTEGER),
                ),
                $expressionBuilder->in(
                    'p.identifier',
                    $queryBuilder->createNamedParameter($identifiers, ArrayParameterType::STRING),
                ),
                $expressionBuilder->neq(
                    'f.'.Configuration::FIELD_IDENTIFIER,
                    $queryBuilder->createNamedParameter(''),
                ),
                $expressionBuilder->neq(
                    'f.'.Configuration::FIELD_IDENTIFIER,
                    $queryBuilder->createNamedParameter(ResourceIdentifier::RemoteInstance->value),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['identifier']] = (int) $row['uid'];
        }

        return $result;
    }

    /**
     * @return array{identifier: string, storage: int, width: int, height: int}|null
     */
    public function findSmallestRendition(int $originalUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $expressionBuilder = $queryBuilder->expr();
        $queryBuilder->select('identifier', 'storage', 'width', 'height', 'task_type')
            ->from('sys_file_processedfile')
            ->where(
                $expressionBuilder->eq('original', $queryBuilder->createNamedParameter($originalUid, ParameterType::INTEGER)),
                $expressionBuilder->gt('width', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $expressionBuilder->gt('height', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $expressionBuilder->neq('identifier', $queryBuilder->createNamedParameter('')),
            )
            // No LIMIT here: the WHERE above already scopes this to the
            // renditions of a single original, a set bounded only by the
            // task types and configurations the site actually uses. Capping
            // it would let a wide Image.Preview thumbnail sort outside the
            // window and silently defeat the preference below, the exact
            // failure mode this method exists to avoid.
            ->addOrderBy('width', 'ASC');

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        if ([] === $rows) {
            return null;
        }

        // The backend thumbnail (task_type 'Image.Preview') is preferred over
        // every other rendition regardless of width. Picking it here in PHP,
        // rather than through an ORDER BY expression on task_type, keeps that
        // preference correct no matter how many other task types a
        // ProcessorRegistry ends up registering (Image.Thumbnail,
        // Image.Watermark, ...); it does not depend on their names sorting a
        // particular way relative to 'Image.Preview'.
        foreach ($rows as $row) {
            if ('Image.Preview' === $row['task_type']) {
                return [
                    'identifier' => (string) $row['identifier'],
                    'storage' => (int) $row['storage'],
                    'width' => (int) $row['width'],
                    'height' => (int) $row['height'],
                ];
            }
        }

        $row = $rows[0];

        return [
            'identifier' => (string) $row['identifier'],
            'storage' => (int) $row['storage'],
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
        ];
    }
}
