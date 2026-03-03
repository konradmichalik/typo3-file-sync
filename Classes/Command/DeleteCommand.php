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

namespace KonradMichalik\Typo3FileSync\Command;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DeleteCommand extends AbstractCommand
{
    protected readonly FileRepository $fileRepository;
    protected readonly LanguageService $languageService;

    public function __construct(?string $name = null, ?FileRepository $fileRepository = null, ?LanguageService $languageService = null)
    {
        parent::__construct($name);

        $this->fileRepository = $fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
        $this->languageService = $languageService ?? $GLOBALS['LANG'];
    }

    public function configure(): void
    {
        $this->setDescription('Deletes files fetched by file-sync')
            ->addOption(
                'identifier',
                'i',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Delete files from specific identifier(s)'
            )
            ->addOption(
                'storage',
                's',
                InputOption::VALUE_OPTIONAL,
                'Delete files from a specific storage only'
            )
            ->addOption(
                'all',
                'a',
                InputOption::VALUE_NONE,
                'Delete all files fetched by file-sync'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifiers = $input->getOption('identifier');
        $storage = $input->getOption('storage');
        $all = $input->getOption('all');

        if (empty($identifiers) && empty($all)) {
            throw new \RuntimeException('No identifier configured neither --all option found.', 1584358697);
        }

        if ($all) {
            $rows = $this->fileRepository->countByIdentifier($storage !== null ? (int)$storage : null);
            $identifiers = array_column($rows, 'tx_typo3_file_sync_identifier');
        }

        $enabledStorages = $this->getEnabledStorages();
        if ($storage !== null) {
            $storage = (int)$storage;
            $enabledStorages = [
                $storage => $enabledStorages[$storage] ?? [],
            ];
        }

        foreach ($enabledStorages as $storageRow) {
            foreach ($identifiers as $identifier) {
                $count = $this->fileRepository->deleteByIdentifier($identifier, $storageRow['uid'] ?? null);
                if ($count) {
                    $resourceTitle = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['resourceHandler'][$identifier]['title'] ?? $identifier;
                    $output->writeln(sprintf(
                        'Deleted %d file(s) from "%s" resource in storage "%s" (uid: %d)',
                        $count,
                        $this->languageService->sL($resourceTitle),
                        $storageRow['name'] ?? 'unknown',
                        $storageRow['uid'] ?? 0
                    ));
                }
            }
        }

        return 0;
    }
}
