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

namespace KonradMichalik\Typo3FileSync\Service;

use Closure;
use Doctrine\DBAL\{ArrayParameterType, ParameterType};
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\{FetchMode, ResourceIdentifier};
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\{File, ProcessedFileRepository, ResourceFactory, ResourceStorage};

use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_file;
use function sprintf;
use function time;
use function unlink;
use function unserialize;

/**
 * MaterializationService.
 *
 * Turns the provisional artefacts a deferred render left on a page into the
 * real renditions: one batch of signed tokens in, one fresh URL per token out.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class MaterializationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * A page carries a bounded number of images. A larger batch is not a
     * render catching up, it is an attempt to make this instance hammer
     * the remote on demand.
     */
    private const MAX_TOKENS = 50;

    /**
     * How long a file the remote could not deliver stays untouched. Without
     * it every visitor of the same page would retry the same failing fetch.
     */
    private const DAMPING_SECONDS = 300;

    public function __construct(
        private readonly DeferredTokenService $deferredTokenService,
        private readonly FetchMode $fetchMode,
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
        private readonly ProcessedFileRepository $processedFileRepository,
        private readonly FileRepository $fileRepository,
    ) {}

    /**
     * @param list<string> $tokens
     *
     * @return array<string, array{url: string}|array{error: string}>
     */
    public function materialize(array $tokens): array
    {
        if (count($tokens) > self::MAX_TOKENS) {
            return [];
        }

        $results = [];
        $uidsByToken = [];
        foreach ($tokens as $token) {
            $uid = $this->deferredTokenService->resolve($token);
            if (null === $uid) {
                $results[$token] = ['error' => 'invalid'];
                continue;
            }
            $uidsByToken[$token] = $uid;
        }

        $processedRows = $this->loadProcessedFiles(array_values($uidsByToken));
        $originalRows = $this->loadOriginals($processedRows);

        $pending = [];
        foreach ($uidsByToken as $token => $uid) {
            $state = $this->classify($processedRows[$uid] ?? null, $originalRows);
            if (is_array($state)) {
                $pending[$token] = $state;
                continue;
            }
            $results[$token] = ['error' => $state];
        }

        if ([] === $pending) {
            return $results;
        }

        // Nothing above this line may touch FAL. Inside a frontend request
        // the driver defers its own fetches, so a storage initialised
        // earlier would keep handing out placeholders instead of fetching.
        $this->fetchMode->forceSynchronous();

        return $results + $this->materializeAll($pending);
    }

    /**
     * @param array<string, mixed>|null        $processedRow
     * @param array<int, array<string, mixed>> $originalRows
     *
     * @return array<string, mixed>|string the processed file row, or the error key describing why it was dropped
     */
    private function classify(?array $processedRow, array $originalRows): array|string
    {
        if (null === $processedRow) {
            return 'invalid';
        }

        $original = $originalRows[(int) $processedRow['original']] ?? null;
        if (null === $original) {
            return 'invalid';
        }

        if (time() - (int) $original[Configuration::FIELD_TSTAMP] < self::DAMPING_SECONDS) {
            return 'throttled';
        }

        return $processedRow;
    }

    /**
     * @param array<string, array<string, mixed>> $pending
     *
     * @return array<string, array{url: string}|array{error: string}>
     */
    private function materializeAll(array $pending): array
    {
        $results = [];
        $files = [];
        $originals = [];
        foreach ($pending as $token => $processedRow) {
            $file = $this->resolveOriginal((int) $processedRow['original']);
            if (null === $file) {
                $results[$token] = ['error' => 'invalid'];
                continue;
            }
            $files[$token] = $file;
            $originals[$file->getUid()] = $file;
        }

        // Grouped by original, not by token: srcset routinely puts several
        // renditions of one picture on a page, and fetching per rendition
        // would delete a real file this batch just paid to download.
        $accepted = $this->prepareStorages($originals);
        $failures = [];
        foreach ($originals as $fileUid => $file) {
            $failure = $this->refetchOriginal($file, $accepted[$file->getStorage()->getUid()] ?? []);
            if (null !== $failure) {
                $failures[$fileUid] = $failure;
            }
        }

        foreach ($files as $token => $file) {
            $results[$token] = $failures[$file->getUid()] ?? $this->rebuildRendition($file, $pending[$token]);
        }

        return $results;
    }

    /**
     * @param list<string> $accepted
     *
     * @return array{error: string}|null null once the real original is on disk
     */
    private function refetchOriginal(File $file, array $accepted): ?array
    {
        try {
            if (!$this->isDelivered($file, $accepted)) {
                // A provisional file on disk stops the driver from reaching
                // for the remote, so it has to go before the fetch. An
                // original a concurrent request already materialized is
                // left alone: deleting it would throw away a real file.
                $provisionalPath = $file->getForLocalProcessing(false);
                if (is_file($provisionalPath)) {
                    unlink($provisionalPath);
                }
            }

            if (is_file($file->getForLocalProcessing(false)) && $this->isDelivered($file, $accepted)) {
                return null;
            }
        } catch (Throwable $exception) {
            $this->logger?->warning(
                sprintf('Fetching original %d failed: %s', $file->getUid(), $exception->getMessage()),
            );
        }

        return $this->damp($file);
    }

    /**
     * A fallback handler such as the placeholder generator will happily put
     * *a* file on disk, which is not a materialization. Only a handler the
     * render skipped counts; reporting otherwise would make the browser
     * swap a placeholder for a placeholder and stop retrying that image.
     *
     * @param list<string> $accepted
     */
    private function isDelivered(File $file, array $accepted): bool
    {
        return in_array($this->fileRepository->findSyncData($file->getUid())['identifier'], $accepted, true);
    }

    /**
     * @param array<string, mixed> $processedRow
     *
     * @return array{url: string}|array{error: string}
     */
    private function rebuildRendition(File $file, array $processedRow): array
    {
        try {
            $this->discardProvisionalRendition((int) $processedRow['uid']);

            // No identifier bookkeeping here on purpose: the handler chain
            // already ran FileRepository::updateIdentifier() for whichever
            // resource delivered the file.
            $configuration = unserialize((string) $processedRow['configuration'], ['allowed_classes' => false]);
            $processedFile = $file->process((string) $processedRow['task_type'], is_array($configuration) ? $configuration : []);
            $publicUrl = $processedFile->getPublicUrl();

            if (null === $publicUrl) {
                return $this->damp($file);
            }

            // The rendition is rebuilt behind the URL the browser already
            // holds, so only the query string makes it load the new bytes.
            return ['url' => $publicUrl.'?v='.time()];
        } catch (Throwable $exception) {
            $this->logger?->warning(
                sprintf('Rebuilding rendition %d failed: %s', $processedRow['uid'], $exception->getMessage()),
            );

            return $this->damp($file);
        }
    }

    /**
     * Only the rendition this token names is dropped. Every other rendition
     * of the same original carries its own token and is handled by its own
     * entry, so dropping them all here would destroy the ones a previous
     * entry of the same batch just rebuilt.
     */
    private function discardProvisionalRendition(int $processedFileUid): void
    {
        $processedFile = $this->processedFileRepository->findByUid($processedFileUid);
        if ($processedFile->exists()) {
            $processedFile->delete(true);
        }
    }

    /**
     * Downloads every original of a storage in one batch and reports which
     * resource identifiers count as materialized there. The render skips
     * exactly the handlers marked DeferrableResourceInterface, so those are
     * the ones whose delivery means the real file arrived.
     * ResourceIdentifier::RemoteInstance is the one the extension ships and
     * the one FileRepository's provisional queries key on; a project adding
     * its own deferrable handler is picked up through the marker interface.
     *
     * @param array<int, File> $originals
     *
     * @return array<int, list<string>> accepted resource identifiers per storage uid
     */
    private function prepareStorages(array $originals): array
    {
        /** @var array<int, array{storage: ResourceStorage, paths: list<string>}> $batches */
        $batches = [];
        foreach ($originals as $file) {
            $storage = $file->getStorage();
            $batches[$storage->getUid()] ??= ['storage' => $storage, 'paths' => []];

            $publicUrl = $file->getPublicUrl();
            if (null !== $publicUrl && '' !== $publicUrl) {
                $batches[$storage->getUid()]['paths'][] = $publicUrl;
            }
        }

        $accepted = [];
        foreach ($batches as $storageUid => $batch) {
            $accepted[$storageUid] = [ResourceIdentifier::RemoteInstance->value];

            $driver = self::extractDriver($batch['storage']);
            if (!$driver instanceof FileSyncDriver) {
                continue;
            }

            $driver->prefetch(array_values(array_unique($batch['paths'])));
            $accepted[$storageUid] = array_values(array_unique(array_merge(
                $accepted[$storageUid],
                $driver->getDeferrableIdentifiers(),
            )));
        }

        return $accepted;
    }

    private function resolveOriginal(int $fileUid): ?File
    {
        try {
            return $this->resourceFactory->getFileObject($fileUid);
        } catch (Throwable $exception) {
            $this->logger?->warning(
                sprintf('Original file %d could not be resolved: %s', $fileUid, $exception->getMessage()),
            );

            return null;
        }
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadProcessedFiles(array $uids): array
    {
        if ([] === $uids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_processedfile');
        $rows = $queryBuilder
            ->select('uid', 'original', 'task_type', 'configuration')
            ->from('sys_file_processedfile')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return self::indexByUid($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $processedRows
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadOriginals(array $processedRows): array
    {
        $originalUids = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['original'],
            $processedRows,
        )));
        if ([] === $originalUids) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $rows = $queryBuilder
            ->select('uid', Configuration::FIELD_TSTAMP)
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($originalUids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return self::indexByUid($rows);
    }

    /**
     * Arms the damping window and reports the failure in one step, so no
     * caller can return 'unavailable' without also blocking the retry.
     *
     * @return array{error: string}
     */
    private function damp(File $file): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $queryBuilder->update('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($file->getUid(), ParameterType::INTEGER),
                ),
            )
            ->set(Configuration::FIELD_TSTAMP, time(), true, ParameterType::INTEGER)
            ->executeStatement();

        return ['error' => 'unavailable'];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private static function indexByUid(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['uid']] = $row;
        }

        return $indexed;
    }

    /**
     * TYPO3 core deliberately keeps the driver private with no public accessor.
     *
     * @see ResourceStorage::$driver (private)
     * @see ResourceStorage::getDriver() (protected)
     */
    private static function extractDriver(ResourceStorage $storage): DriverInterface
    {
        return Closure::bind(static fn () => $storage->driver, null, ResourceStorage::class)();
    }
}
