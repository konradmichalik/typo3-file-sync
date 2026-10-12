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

namespace KonradMichalik\Typo3FileSync\Command;

use Generator;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\{FetchMode, StorageDriver};
use KonradMichalik\Typo3FileSync\Service\StorageService;
use LimitIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_sum;
use function count;
use function implode;
use function sprintf;
use function usleep;

/**
 * FetchCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FetchCommand extends Command
{
    private const CHUNK_SIZE = 50;

    public function __construct(
        private readonly StorageService $storageService,
        private readonly StorageRepository $storageRepository,
        private readonly FileRepository $fileRepository,
        private readonly FetchMode $fetchMode,
        private readonly FrontendInterface $runtimeCache,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setDescription('Fetches missing files of synced storages in bulk')
            ->addOption('storage', 's', InputOption::VALUE_REQUIRED, 'Fetch files of a specific storage only')
            ->addOption(
                'identifier',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Use only these resource handler(s). Without it, every handler except placeholder handlers is used',
            )
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Fetch only files whose identifier starts with this path', '/')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Fetch at most this many files, 0 for no limit', '0')
            ->addOption('pause', null, InputOption::VALUE_REQUIRED, 'Milliseconds to wait between two batches', '0')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report the missing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storages = $this->selectStorages($input->getOption('storage'));
        if (null === $storages) {
            $output->writeln(sprintf('<error>Storage %s is not enabled for file sync.</error>', $input->getOption('storage')));

            return Command::FAILURE;
        }

        /** @var list<string> $identifiers */
        $identifiers = $input->getOption('identifier');
        $error = $this->validate($identifiers, (int) $input->getOption('limit'), (int) $input->getOption('pause'));
        if (null !== $error) {
            $output->writeln(sprintf('<error>%s</error>', $error));

            return Command::FAILURE;
        }

        if ([] === $identifiers) {
            $this->fetchMode->skipGenerating();
        } else {
            $this->fetchMode->restrictTo($identifiers);
        }

        // FetchMode is a process-wide singleton, and the scheduler runs every
        // due task in one process.
        try {
            return $this->fetchStorages($storages, $input, $output);
        } finally {
            $this->fetchMode->clearHandlerRestriction();
        }
    }

    /**
     * @param list<string> $identifiers
     */
    private function validate(array $identifiers, int $limit, int $pauseMs): ?string
    {
        if ($limit < 0 || $pauseMs < 0) {
            return 'Limit and pause must not be negative.';
        }

        $registered = array_keys($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_RESOURCE_HANDLER] ?? []);
        $unknown = array_diff($identifiers, $registered);

        return [] === $unknown ? null : sprintf('Unknown resource handler(s): %s', implode(', ', $unknown));
    }

    /**
     * @param array<int, array{uid: int, name: string}> $storages
     */
    private function fetchStorages(array $storages, InputInterface $input, OutputInterface $output): int
    {
        $pathPrefix = '/'.ltrim((string) $input->getOption('path'), '/');
        $limit = (int) $input->getOption('limit');
        $remaining = $limit > 0 ? $limit : \PHP_INT_MAX;
        $failed = 0;

        foreach ($storages as $storageRow) {
            if ($remaining <= 0) {
                break;
            }

            $label = sprintf('Storage "%s" (uid: %d)', $storageRow['name'], $storageRow['uid']);
            $driver = StorageDriver::extract($this->storageRepository->getStorageObject(max(0, $storageRow['uid'])));
            if (!$driver instanceof FileSyncDriver) {
                $output->writeln(sprintf('%s: no resource handler configured, skipped', $label));
                if (null !== $input->getOption('storage')) {
                    return Command::FAILURE;
                }
                continue;
            }

            $missing = new LimitIterator($this->missingFiles($driver, $storageRow['uid'], $pathPrefix), 0, $remaining);

            if (true === $input->getOption('dry-run')) {
                $remaining -= $this->reportMissing($missing, $label, $output);
                continue;
            }

            $counts = $this->fetchFiles($driver, $missing, (int) $input->getOption('pause'), $output);
            $remaining -= array_sum($counts);
            $failed += $counts['failed'];
            $output->writeln(sprintf('%s: %d fetched, %d unavailable, %d failed', $label, $counts['fetched'], $counts['unavailable'], $counts['failed']));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<int, array{uid: int, name: string}>|null null when the requested storage is not enabled
     */
    private function selectStorages(mixed $storage): ?array
    {
        $enabledStorages = $this->storageService->getEnabledStorages();
        if (null === $storage) {
            return $enabledStorages;
        }

        $storageUid = (int) $storage;

        return isset($enabledStorages[$storageUid]) ? [$storageUid => $enabledStorages[$storageUid]] : null;
    }

    /**
     * @return Generator<int, array{identifier: non-empty-string, size: int}>
     */
    private function missingFiles(FileSyncDriver $driver, int $storageUid, string $pathPrefix): Generator
    {
        foreach ($this->fileRepository->findByPathPrefix($storageUid, $pathPrefix) as $file) {
            $identifier = $file['identifier'];
            if ('' !== $identifier && !$driver->existsLocally($identifier)) {
                yield ['identifier' => $identifier, 'size' => $file['size']];
            }
        }
    }

    /**
     * @param iterable<array{identifier: non-empty-string, size: int}> $files
     *
     * @return int the number of missing files
     */
    private function reportMissing(iterable $files, string $label, OutputInterface $output): int
    {
        $count = 0;
        $size = 0;
        foreach ($files as $file) {
            ++$count;
            $size += $file['size'];
        }

        $output->writeln(sprintf('%s: %d missing file(s), %s', $label, $count, GeneralUtility::formatSize($size, ' B| KB| MB| GB')));

        return $count;
    }

    /**
     * @param iterable<array{identifier: non-empty-string, size: int}> $files
     *
     * @return array{fetched: int, unavailable: int, failed: int}
     */
    private function fetchFiles(FileSyncDriver $driver, iterable $files, int $pauseMs, OutputInterface $output): array
    {
        $counts = ['fetched' => 0, 'unavailable' => 0, 'failed' => 0];
        $progressBar = new ProgressBar($output);

        foreach ($this->chunk($files) as $index => $chunk) {
            if ($index > 0 && $pauseMs > 0) {
                usleep($pauseMs * 1000);
            }

            $this->prefetch($driver, $chunk, $output);
            foreach ($chunk as $identifier) {
                ++$counts[$this->fetchFile($driver, $identifier, $output)];
                $progressBar->advance();
            }

            // Keeps memory flat however large the storage is, and leaves no
            // file marked as failed behind for a later run in this process.
            $driver->clearCaches();
            $this->runtimeCache->flush();
        }

        $progressBar->finish();
        $output->writeln('');

        return $counts;
    }

    /**
     * @param iterable<array{identifier: non-empty-string, size: int}> $files
     *
     * @return Generator<int, list<non-empty-string>>
     */
    private function chunk(iterable $files): Generator
    {
        $chunk = [];
        foreach ($files as $file) {
            $chunk[] = $file['identifier'];
            if (self::CHUNK_SIZE === count($chunk)) {
                yield $chunk;
                $chunk = [];
            }
        }

        if ([] !== $chunk) {
            yield $chunk;
        }
    }

    /**
     * A batch that cannot be prefetched only loses its speed: every file is
     * still fetched one by one afterwards.
     *
     * @param list<non-empty-string> $identifiers
     */
    private function prefetch(FileSyncDriver $driver, array $identifiers, OutputInterface $output): void
    {
        try {
            $paths = array_map(static fn (string $identifier): string => $driver->getRemotePath($identifier) ?? '', $identifiers);
            $driver->prefetch(array_values(array_filter($paths, static fn (string $path): bool => '' !== $path)));
        } catch (Throwable $exception) {
            $output->writeln(sprintf('Prefetch failed: %s', $exception->getMessage()), OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    /**
     * @param non-empty-string $identifier
     *
     * @return 'fetched'|'unavailable'|'failed'
     */
    private function fetchFile(FileSyncDriver $driver, string $identifier, OutputInterface $output): string
    {
        try {
            if ($driver->fileExists($identifier)) {
                return 'fetched';
            }

            $output->writeln(sprintf(' Unavailable: %s', $identifier), OutputInterface::VERBOSITY_VERBOSE);

            return 'unavailable';
        } catch (Throwable $exception) {
            $output->writeln(sprintf(' Failed: %s (%s)', $identifier, $exception->getMessage()), OutputInterface::VERBOSITY_VERBOSE);

            return 'failed';
        }
    }
}
