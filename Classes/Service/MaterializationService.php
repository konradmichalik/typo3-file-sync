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

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\{FetchMode, ResourceIdentifier, StorageDriver};
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;
use TYPO3\CMS\Core\Resource\{File, ProcessedFileRepository, ResourceFactory, ResourceStorage};

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function sprintf;
use function time;
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
    public const MAX_TOKENS = 50;

    /**
     * How long a file the remote could not deliver stays untouched. Without
     * it every visitor of the same page would retry the same failing fetch.
     *
     * Public for the same reason MAX_TOKENS is: the preview stage of this
     * endpoint damps for the same window, and two definitions of "how long a
     * dead fetch stays dead" would drift into two answers for one endpoint.
     */
    public const DAMPING_SECONDS = 300;

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

        return $results + $this->materializeAll($pending, $syncData);
    }

    /**
     * @param array<string, mixed>|null                          $processedRow
     * @param array<int, array{identifier: string, failed: int}> $syncData
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
        // rebuilt, and a marker left over from an earlier failure would
        // hold them back for the rest of the window.
        if (in_array($original['identifier'], self::materializedIdentifiers(), true)) {
            return $processedRow;
        }

        // Only a failed fetch damps. The render that produced the
        // placeholder this batch is here to replace is a success, and it
        // happened milliseconds ago, so reading anything a successful
        // render writes would throttle every first visit.
        return time() - $original['failed'] < self::DAMPING_SECONDS ? 'throttled' : $processedRow;
    }

    /**
     * @param array<string, array<string, mixed>>                $pending
     * @param array<int, array{identifier: string, failed: int}> $syncData
     *
     * @return array<string, array{url: string}|array{error: string}>
     */
    private function materializeAll(array $pending, array $syncData): array
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
        $accepted = $this->prepareStorages($originals, $syncData);
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
        // The provisional file has to get out of the way before the driver
        // will fetch anything, but this is a public endpoint that must not
        // leave the storage worse than it found it: an identifier of
        // placeholder_image on top of real bytes is the ordinary state of an
        // instance whose files were rsynced after its database was synced.
        $stashed = null;

        try {
            // getForLocalProcessing() is not a path getter: it runs through
            // FileSyncDriver::ensureFileExists(), which fetches when the
            // file is absent. The guard therefore has to be read after it,
            // or a provisional-but-absent original would be downloaded for
            // real here and moved aside on the next line.
            $provisionalPath = $file->getForLocalProcessing(false);
            if (!$this->isDelivered($file, $accepted) && is_file($provisionalPath)) {
                $stashed = StashedFile::stash($provisionalPath);
            }

            if (is_file($file->getForLocalProcessing(false)) && $this->isDelivered($file, $accepted)) {
                $stashed?->discard();

                return null;
            }
        } catch (Throwable $exception) {
            $this->logger?->warning(
                sprintf('Fetching original %d failed: %s', $file->getUid(), $exception->getMessage()),
            );
        }

        $stashed?->restore();

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
        $stashed = null;

        try {
            $stashed = $this->stashProvisionalRendition((int) $processedRow['uid']);

            // No identifier bookkeeping here on purpose: the handler chain
            // already ran FileRepository::updateIdentifier() for whichever
            // resource delivered the file.
            $configuration = unserialize((string) $processedRow['configuration'], ['allowed_classes' => false]);
            $processedFile = $file->process((string) $processedRow['task_type'], is_array($configuration) ? $configuration : []);
            $publicUrl = $processedFile->getPublicUrl();

            if (null === $publicUrl) {
                // @codeCoverageIgnoreStart
                // Not reproducible against a real image processor: every
                // corruption tried here made process() fall back to the
                // original file rather than hand back a ProcessedFile
                // marked deleted.
                $stashed?->restore();

                return $this->damp($file);
                // @codeCoverageIgnoreEnd
            }

            $stashed?->discard();

            // The rendition is rebuilt behind the URL the browser already
            // holds, so only the query string makes it load the new bytes.
            // SitePath::absolute() because this request is answered before
            // site resolution: without TSFE nothing prefixes the leading
            // slash, and the browser would resolve "fileadmin/..." against
            // the page it is on rather than against the document root.
            return ['url' => SitePath::absolute($publicUrl).'?v='.time()];
        } catch (Throwable $exception) {
            $stashed?->restore();
            $this->logger?->warning(
                sprintf('Rebuilding rendition %d failed: %s', $processedRow['uid'], $exception->getMessage()),
            );

            return $this->damp($file);
        }
    }

    /**
     * Only the rendition this token names is moved aside. Every other
     * rendition of the same original carries its own token and is handled by
     * its own entry, so dropping them all here would destroy the ones a
     * previous entry of the same batch just rebuilt.
     *
     * Moved rather than deleted, and the sys_file_processedfile row is left
     * alone. process() reprocesses on a missing file either way, but a delete
     * could not be undone: if the rebuild then fails, the already cached HTML
     * still points at that URL, and the original is by now marked
     * remote_instance, so no later render classifies this rendition as
     * provisional and nothing ever retries it. The visitor would be left with
     * a permanently broken image rather than the placeholder that was there.
     */
    private function stashProvisionalRendition(int $processedFileUid): ?StashedFile
    {
        $processedFile = $this->processedFileRepository->findByUid($processedFileUid);
        if (!$processedFile->exists()) {
            return null;
        }

        $stashed = StashedFile::stash($processedFile->getForLocalProcessing(false));
        // The row has to go with it: process() reuses an existing row and
        // would hand back a URL for bytes that are no longer there. The file
        // is already moved aside, so this only drops the database record.
        $processedFile->delete(true);

        return $stashed;
    }

    /**
     * Downloads every original of a storage in one batch and reports which
     * resource identifiers count as materialized there. The render skips
     * exactly the handlers marked DeferrableResourceInterface, so those are
     * the ones whose delivery means the real file arrived.
     *
     * @param array<int, File>                                   $originals
     * @param array<int, array{identifier: string, failed: int}> $syncData
     *
     * @return array<int, list<string>> accepted resource identifiers per storage uid
     */
    private function prepareStorages(array $originals, array $syncData): array
    {
        /** @var array<int, array{storage: ResourceStorage, files: list<File>}> $batches */
        $batches = [];
        foreach ($originals as $file) {
            try {
                $storage = $file->getStorage();
                $batches[$storage->getUid()] ??= ['storage' => $storage, 'files' => []];
                $batches[$storage->getUid()]['files'][] = $file;
                // @codeCoverageIgnoreStart
                // Unreachable under normal operation: $file was resolved via
                // resolveOriginal(), which already means its storage was
                // resolved too, and AbstractFile::getStorage() only ever
                // returns that already-resolved value.
            } catch (Throwable $exception) {
                $this->logger?->warning(
                    sprintf('Storage of file %d is unavailable: %s', $file->getUid(), $exception->getMessage()),
                );
            }
            // @codeCoverageIgnoreEnd
        }

        $accepted = [];
        foreach ($batches as $storageUid => $batch) {
            // A storage that cannot be prepared costs the batch its
            // prefetch, never its answers: every token of that storage
            // still runs, one serial fetch at a time.
            $accepted[$storageUid] = self::materializedIdentifiers();

            try {
                $accepted[$storageUid] = $this->prepareStorage($batch['storage'], $batch['files'], $syncData);
            } catch (Throwable $exception) {
                $this->logger?->warning(
                    sprintf('Prefetch for storage %d failed: %s', $storageUid, $exception->getMessage()),
                );
            }
        }

        return $accepted;
    }

    /**
     * @param list<File>                                         $files
     * @param array<int, array{identifier: string, failed: int}> $syncData
     *
     * @return list<string>
     */
    private function prepareStorage(ResourceStorage $storage, array $files, array $syncData): array
    {
        $driver = StorageDriver::extract($storage);
        if (!$driver instanceof FileSyncDriver) {
            return self::materializedIdentifiers();
        }

        $deferrable = $driver->getDeferrableIdentifiers();
        $accepted = array_values(array_unique(array_merge(
            self::materializedIdentifiers(),
            $deferrable,
        )));

        // This is the one place that sees both definitions of "provisional":
        // the one this service uses, which is every deferrable handler, and
        // the one FileRepository queries with, which is the single identifier
        // it can name in SQL. They agree only while there is one deferrable
        // handler. Past that a file is delivered here and still provisional
        // there, so the next render defers it again and the loop never ends.
        if (count($deferrable) > 1) {
            $this->logger?->warning(sprintf(
                'Storage %d has %d deferrable resource handlers (%s). Only "%s" counts as materialized in the database, so the others are deferred again on every render. Implementing DeferrableResourceInterface outside this extension is not supported.',
                $storage->getUid(),
                count($deferrable),
                implode(', ', $deferrable),
                ResourceIdentifier::RemoteInstance->value,
            ));
        }

        $paths = [];
        foreach ($files as $file) {
            // An original that is already the real file has nothing to
            // download. Without this the next lazy-load batch would fetch
            // every picture the previous one just materialized again.
            if (in_array($syncData[$file->getUid()]['identifier'] ?? '', $accepted, true)) {
                continue;
            }

            // Taken from the original driver, exactly as
            // FileSyncDriver::ensureFileExists() does it. Going through
            // File::getPublicUrl() would dispatch
            // GeneratePublicUrlForResourceEvent, so a listener or a CDN
            // base URL could produce a key the driver never looks up, and
            // it would also fetch each file serially before the pool runs.
            $paths[] = $driver->getRemotePath($file->getIdentifier()) ?? '';
        }

        $driver->prefetch(array_values(array_unique(array_filter(
            $paths,
            static fn (string $path): bool => '' !== $path,
        ))));

        return $accepted;
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
        $this->fileRepository->markFetchFailure($file->getUid());

        return ['error' => 'unavailable'];
    }
}
