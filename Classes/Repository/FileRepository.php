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
     * Batched sibling of findSyncData() for a whole materialization request,
     * which asks a different question than the backend does: not when a
     * handler last delivered, but whether an on-demand fetch failed recently
     * enough to still be damped.
     *
     * @param list<int> $fileUids
     *
     * @return array<int, array{identifier: string, failed: int}>
     */
    public function findSyncDataByUids(array $fileUids): array
    {
        if ([] === $fileUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $rows = $queryBuilder
            ->select('uid', Configuration::FIELD_IDENTIFIER, Configuration::FIELD_FAILED)
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
                'failed' => (int) ($row[Configuration::FIELD_FAILED] ?? 0),
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
            // storage, identifier, width and height are the preview stage's.
            // It keys a stored preview by the rendition the browser is waiting
            // for and crops to that rendition's shape, not to the shape of the
            // far smaller one it downloads.
            ->select('uid', 'original', 'task_type', 'configuration', 'storage', 'identifier', 'width', 'height')
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
     * Records that an on-demand fetch just failed, which arms the damping
     * window that keeps a file the remote cannot deliver from being retried
     * on every page view.
     *
     * Its own field rather than the sync timestamp: that one says when a
     * handler delivered, and a deferred render delivering a placeholder
     * writes it milliseconds before the browser asks for the real file. Read
     * as a failure, it damped the very request the render was made for.
     */
    public function markFetchFailure(int $fileUid): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->update('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($fileUid, ParameterType::INTEGER),
                ),
            )
            ->set(Configuration::FIELD_FAILED, time(), true, ParameterType::INTEGER)
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
     * The storage comes off the rendition's own row rather than off the list
     * that was queried: together with the identifier it is the key a stored
     * preview lives under, and a caller passes every deferred storage at once.
     *
     * Keying the result by the identifier alone is nevertheless safe across
     * those storages: ProcessedFile builds every processed basename from the
     * original's own sys_file uid, a primary key all storages share, so two
     * different originals cannot collide however their storages are
     * configured. That argument covers different originals and nothing else.
     * It says nothing about a driver that invents its own processed names,
     * and nothing about a storage whose processing folder was repointed at a
     * path a second storage also serves.
     *
     * @param list<int>    $storageUids
     * @param list<string> $identifiers
     *
     * @return array<string, array{uid: int, storage: int}>
     */
    public function findProvisionalProcessedFiles(array $storageUids, array $identifiers): array
    {
        if ([] === $storageUids || [] === $identifiers) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $expressionBuilder = $queryBuilder->expr();
        $rows = $queryBuilder
            ->select('p.uid', 'p.identifier', 'p.storage')
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
            $result[(string) $row['identifier']] = ['uid' => (int) $row['uid'], 'storage' => (int) $row['storage']];
        }

        return $result;
    }

    /**
     * The smallest usable rendition of each original, in one query.
     *
     * Batched rather than asked per original, because the WHERE below is
     * deliberately uncapped and the caller runs up to fifty tokens through
     * it on a public request: one per token is fifty unbounded queries in
     * one call.
     *
     * @param list<int> $originalUids
     *
     * @return array<int, array{identifier: string, storage: int, width: int, height: int}> keyed by original uid, absent where that original has no usable rendition
     */
    public function findSmallestRenditions(array $originalUids): array
    {
        if ([] === $originalUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $expressionBuilder = $queryBuilder->expr();
        $queryBuilder->select('original', 'identifier', 'storage', 'width', 'height', 'task_type')
            ->from('sys_file_processedfile')
            ->where(
                $expressionBuilder->in('original', $queryBuilder->createNamedParameter($originalUids, ArrayParameterType::INTEGER)),
                $expressionBuilder->gt('width', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $expressionBuilder->gt('height', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                $expressionBuilder->neq('identifier', $queryBuilder->createNamedParameter('')),
            )
            // No LIMIT here: the WHERE above already scopes this to the
            // renditions of the originals asked for, a set bounded only by the
            // task types and configurations the site actually uses. Capping
            // it would let a wide Image.Preview thumbnail sort outside the
            // window and silently defeat the preference below, the exact
            // failure mode this method exists to avoid.
            ->addOrderBy('width', 'ASC')
            // Two renditions of one picture can record the same width, and
            // without a tiebreaker the winner is whatever the plan yields
            // first, which differs between MariaDB and SQLite. The preview
            // source would then be a different picture per database.
            ->addOrderBy('uid', 'ASC');

        return self::narrowestPerOriginal($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * Groups rows already ordered narrowest-first into one winner per
     * original.
     *
     * The backend thumbnail (task_type 'Image.Preview') is preferred over
     * every other rendition regardless of width. Picking it here in PHP,
     * rather than through an ORDER BY expression on task_type, keeps that
     * preference correct no matter how many other task types a
     * ProcessorRegistry ends up registering (Image.Thumbnail,
     * Image.Watermark, ...); it does not depend on their names sorting a
     * particular way relative to 'Image.Preview'.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, array{identifier: string, storage: int, width: int, height: int}>
     */
    private static function narrowestPerOriginal(array $rows): array
    {
        $winners = [];
        $settled = [];
        foreach ($rows as $row) {
            $original = (int) $row['original'];
            $isThumbnail = 'Image.Preview' === $row['task_type'];
            if (($settled[$original] ?? false) || (isset($winners[$original]) && !$isThumbnail)) {
                continue;
            }

            $winners[$original] = [
                'identifier' => (string) $row['identifier'],
                'storage' => (int) $row['storage'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
            ];
            $settled[$original] = $isThumbnail;
        }

        return $winners;
    }
}
