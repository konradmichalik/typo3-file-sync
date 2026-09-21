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
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\{FetchMode, ResourceIdentifier};
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\{File, ProcessedFileRepository, ResourceFactory, ResourceStorage};

use function array_map;
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

        $processedRows = $this->fileRepository->findProcessedFilesByUids(array_values($uidsByToken));
        $syncData = $this->fileRepository->findSyncDataByUids(array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['original'],
            $processedRows,
        ))));

        $pending = [];
        foreach ($uidsByToken as $token => $uid) {
            $state = $this->classify($processedRows[$uid] ?? null, $syncData);
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
     * @param array<string, mixed>|null                          $processedRow
     * @param array<int, array{identifier: string, tstamp: int}> $syncData
     *
     * @return array<string, mixed>|string the processed file row, or the error key describing why it was dropped
     */
    private function classify(?array $processedRow, array $syncData): array|string
    {
        $original = null === $processedRow ? null : ($syncData[(int) $processedRow['original']] ?? null);
        if (null === $processedRow || null === $original) {
            return 'invalid';
        }

        // An original that already carries a materialized identifier is
        // done, not throttled: its remaining renditions must still be
        // rebuilt. tx_typo3_file_sync_tstamp is written by damp() on
        // failure and by updateIdentifier() on success, so a fresh
        // timestamp alone does not mean "failed recently".
        if (in_array($original['identifier'], self::materializedIdentifiers(), true)) {
            return $processedRow;
        }

        return time() - $original['tstamp'] < self::DAMPING_SECONDS ? 'throttled' : $processedRow;
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
            // getForLocalProcessing() is not a path getter: it runs through
            // FileSyncDriver::ensureFileExists(), which fetches when the
            // file is absent. The guard therefore has to be read after it,
            // or a provisional-but-absent original would be downloaded for
            // real here and unlinked on the next line.
            $provisionalPath = $file->getForLocalProcessing(false);
            if (!$this->isDelivered($file, $accepted) && is_file($provisionalPath)) {
                unlink($provisionalPath);
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
     *
     * @param array<int, File> $originals
     *
     * @return array<int, list<string>> accepted resource identifiers per storage uid
     */
    private function prepareStorages(array $originals): array
    {
        /** @var array<int, array{storage: ResourceStorage, files: list<File>}> $batches */
        $batches = [];
        foreach ($originals as $file) {
            try {
                $storage = $file->getStorage();
                $batches[$storage->getUid()] ??= ['storage' => $storage, 'files' => []];
                $batches[$storage->getUid()]['files'][] = $file;
            } catch (Throwable $exception) {
                $this->logger?->warning(
                    sprintf('Storage of file %d is unavailable: %s', $file->getUid(), $exception->getMessage()),
                );
            }
        }

        $accepted = [];
        foreach ($batches as $storageUid => $batch) {
            // A storage that cannot be prepared costs the batch its
            // prefetch, never its answers: every token of that storage
            // still runs, one serial fetch at a time.
            $accepted[$storageUid] = self::materializedIdentifiers();

            try {
                $accepted[$storageUid] = $this->prepareStorage($batch['storage'], $batch['files']);
            } catch (Throwable $exception) {
                $this->logger?->warning(
                    sprintf('Prefetch for storage %d failed: %s', $storageUid, $exception->getMessage()),
                );
            }
        }

        return $accepted;
    }

    /**
     * @param list<File> $files
     *
     * @return list<string>
     */
    private function prepareStorage(ResourceStorage $storage, array $files): array
    {
        $driver = self::extractDriver($storage);
        if (!$driver instanceof FileSyncDriver) {
            return self::materializedIdentifiers();
        }

        $paths = [];
        foreach ($files as $file) {
            // Taken from the original driver, exactly as
            // FileSyncDriver::ensureFileExists() does it. Going through
            // File::getPublicUrl() would dispatch
            // GeneratePublicUrlForResourceEvent, so a listener or a CDN
            // base URL could produce a key the driver never looks up, and
            // it would also fetch each file serially before the pool runs.
            $path = $driver->getRemotePath($file->getIdentifier());
            if (null !== $path && '' !== $path) {
                $paths[] = $path;
            }
        }

        $driver->prefetch(array_values(array_unique($paths)));

        return array_values(array_unique(array_merge(
            self::materializedIdentifiers(),
            $driver->getDeferrableIdentifiers(),
        )));
    }

    /**
     * The identifiers that mean "materialized" without a storage in hand.
     * prepareStorage() widens this with the storage's own deferrable
     * handlers; this baseline is what FileRepository's provisional queries
     * key on and all that is knowable before FAL is touched.
     *
     * @return list<string>
     */
    private static function materializedIdentifiers(): array
    {
        return [ResourceIdentifier::RemoteInstance->value];
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
     * Arms the damping window and reports the failure in one step, so no
     * caller can return 'unavailable' without also blocking the retry.
     *
     * @return array{error: string}
     */
    private function damp(File $file): array
    {
        $this->fileRepository->touchSyncTimestamp($file->getUid());

        return ['error' => 'unavailable'];
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
