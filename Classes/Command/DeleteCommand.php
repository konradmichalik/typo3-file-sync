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

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Service\StorageService;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory};

use function sprintf;

/**
 * DeleteCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DeleteCommand extends Command
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly FileRepository $fileRepository,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setDescription('Deletes files fetched by file-sync')
            ->addOption(
                'identifier',
                'i',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Delete files from specific identifier(s)',
            )
            ->addOption(
                'storage',
                's',
                InputOption::VALUE_OPTIONAL,
                'Delete files from a specific storage only',
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Delete all files fetched by file-sync',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $identifiers */
        $identifiers = $input->getOption('identifier');
        $storage = $input->getOption('storage');
        $all = $input->getOption('all');

        if ([] === $identifiers && true !== $all) {
            throw new RuntimeException('No identifier configured neither --all option found.', 1584358697);
        }

        $storageUid = null !== $storage ? (int) $storage : null;
        if (true === $all) {
            $rows = $this->fileRepository->countByIdentifier($storageUid);
            $identifiers = array_column($rows, Configuration::FIELD_IDENTIFIER);
        }

        $enabledStorages = $this->storageService->getEnabledStorages();
        if (null !== $storageUid) {
            $enabledStorages = [
                $storageUid => $enabledStorages[$storageUid] ?? ['uid' => $storageUid, 'name' => ''],
            ];
        }

        $this->deleteAndReport($enabledStorages, $identifiers, $output);

        return 0;
    }

    /**
     * @param array<int, array{uid?: int, name?: string}> $enabledStorages
     * @param list<string>                                $identifiers
     */
    private function deleteAndReport(array $enabledStorages, array $identifiers, OutputInterface $output): void
    {
        $languageService = $this->languageServiceFactory->create('default');

        foreach ($enabledStorages as $storageRow) {
            foreach ($identifiers as $identifier) {
                $this->deleteIdentifierFromStorage($storageRow, $identifier, $languageService, $output);
            }
        }
    }

    /**
     * @param array{uid?: int, name?: string} $storageRow
     */
    private function deleteIdentifierFromStorage(array $storageRow, string $identifier, LanguageService $languageService, OutputInterface $output): void
    {
        $count = $this->fileRepository->deleteByIdentifier($identifier, $storageRow['uid'] ?? null);
        if ($count <= 0) {
            return;
        }

        $resourceTitle = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_RESOURCE_HANDLER][$identifier]['title'] ?? $identifier;
        $output->writeln(sprintf(
            'Deleted %d file(s) from "%s" resource in storage "%s" (uid: %d)',
            $count,
            $languageService->sL($resourceTitle),
            $storageRow['name'] ?? 'unknown',
            $storageRow['uid'] ?? 0,
        ));
    }
}
