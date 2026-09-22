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

namespace KonradMichalik\Typo3FileSync\Resource\Preview;

use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\StorageDriver;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;
use TYPO3\CMS\Core\Resource\StorageRepository;

use function array_key_exists;
use function array_unique;
use function array_values;
use function fclose;
use function is_resource;
use function is_string;
use function sprintf;
use function stream_get_contents;

/**
 * PreviewSourceReader.
 *
 * Downloads the renditions a batch of previews is built from: one concurrent
 * prefetch per storage, then the bytes, each remote path fetched once however
 * many callers asked for it.
 *
 * Separate from PreviewService because the two answer different questions.
 * That one decides what a token deserves, from the database and the store;
 * this one knows how to get bytes out of a remote instance without letting
 * FAL write anything to disk.
 *
 * Fail-soft throughout: a caller whose rendition cannot be read gets null and
 * turns that into the grey placeholder the visitor already sees.
 *
 * @phpstan-type PreviewLocation array{storage: int, identifier: string}
 * @phpstan-type PreviewFetch array{storage: int, identifier: string, path: string}
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PreviewSourceReader implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(private readonly StorageRepository $storageRepository) {}

    /**
     * @param array<string, PreviewLocation> $locations keyed by whatever the caller identifies a request by
     *
     * @return array<string, string|null> the bytes per caller key, null where the rendition could not be read
     */
    public function read(array $locations): array
    {
        $sources = $this->resolveSources($locations);
        $this->prefetch($sources);

        $bytes = [];
        $results = [];
        foreach ($locations as $key => $location) {
            $source = $sources[$key] ?? null;
            if (null === $source) {
                $results[$key] = null;
                continue;
            }

            // Cached by path, not by caller: srcset routinely puts several
            // renditions of one picture on a page, and they all resolve to
            // the same smallest rendition. The prefetch buffer hands a path
            // out exactly once, so a second read would go over the wire.
            if (!array_key_exists($source['path'], $bytes)) {
                $bytes[$source['path']] = $this->fetch($source);
            }

            $results[$key] = $bytes[$source['path']];
        }

        return $results;
    }

    /**
     * @param array<string, PreviewLocation> $locations
     *
     * @return array<string, PreviewFetch>
     */
    private function resolveSources(array $locations): array
    {
        $sources = [];
        foreach ($locations as $key => $location) {
            // Through the driver, never through getPublicUrl() on the file:
            // that dispatches GeneratePublicUrlForResourceEvent, so a project
            // listener or a CDN base URL would yield a string the prefetch
            // buffer was never filled under. The buffer would fill, nobody
            // would read it, and the only trace would be a second request.
            $path = $this->driver($location['storage'])?->getRemotePath($location['identifier']);
            if (null === $path || '' === $path) {
                continue;
            }

            $sources[$key] = [
                'storage' => $location['storage'],
                'identifier' => $location['identifier'],
                'path' => $path,
            ];
        }

        return $sources;
    }

    /**
     * @param array<string, PreviewFetch> $sources
     */
    private function prefetch(array $sources): void
    {
        $pathsByStorage = [];
        foreach ($sources as $source) {
            $pathsByStorage[$source['storage']][] = $source['path'];
        }

        foreach ($pathsByStorage as $storageUid => $paths) {
            try {
                $this->driver($storageUid)?->prefetch(array_values(array_unique($paths)));
            } catch (Throwable $exception) {
                // A storage whose prefetch fails costs the batch its
                // concurrency, never its previews: every source still runs,
                // one serial fetch at a time.
                $this->logger?->warning(
                    sprintf('Preview prefetch for storage %d failed: %s', $storageUid, $exception->getMessage()),
                );
            }
        }
    }

    /**
     * Reads a rendition straight from the batch handlers rather than through
     * FAL. Going through the storage would write the downloaded rendition
     * into the processing folder, and going through the full handler chain
     * would let a fallback handler answer with a generated placeholder,
     * which is the grey box a preview exists to replace.
     *
     * @param PreviewFetch $source
     */
    private function fetch(array $source): ?string
    {
        $driver = $this->driver($source['storage']);
        if (null === $driver) {
            return null;
        }

        foreach ($driver->getBatchHandlers() as $handler) {
            try {
                $bytes = self::readStream($handler->getFile($source['identifier'], $source['path']));
            } catch (Throwable $exception) {
                $this->logger?->warning(
                    sprintf('Fetching preview source %s failed: %s', $source['path'], $exception->getMessage()),
                );
                continue;
            }

            if (null !== $bytes) {
                return $bytes;
            }
        }

        return null;
    }

    private static function readStream(mixed $stream): ?string
    {
        if (!is_resource($stream)) {
            return null;
        }

        $bytes = stream_get_contents($stream);
        fclose($stream);

        return is_string($bytes) && '' !== $bytes ? $bytes : null;
    }

    private function driver(int $storageUid): ?FileSyncDriver
    {
        try {
            $storage = $this->storageRepository->findByUid($storageUid);
            $driver = null === $storage ? null : StorageDriver::extract($storage);
        } catch (Throwable $exception) {
            $this->logger?->warning(
                sprintf('Storage %d is unavailable: %s', $storageUid, $exception->getMessage()),
            );

            return null;
        }

        return $driver instanceof FileSyncDriver ? $driver : null;
    }
}
