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

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Service\StorageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

use function sprintf;

/**
 * ResetCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class ResetCommand extends Command
{
    public function __construct(
        private readonly StorageService $storageService,
        private readonly FileRepository $fileRepository,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setDescription('Resets missing files')
            ->addOption(
                'storage',
                's',
                InputOption::VALUE_OPTIONAL,
                'Reset files from a specific storage only',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storage = $input->getOption('storage');

        $enabledStorages = $this->storageService->getEnabledStorages();
        if (null !== $storage) {
            $storageUid = (int) $storage;
            $enabledStorages = [
                $storageUid => $enabledStorages[$storageUid] ?? ['uid' => $storageUid, 'name' => ''],
            ];
        }

        foreach ($enabledStorages as $storageRow) {
            $count = $this->fileRepository->resetMissing($storageRow['uid']);
            if ($count > 0) {
                $output->writeln(sprintf(
                    'Reset %d file(s) in storage "%s" (uid: %d)',
                    $count,
                    $storageRow['name'],
                    $storageRow['uid'],
                ));
            }
        }

        return 0;
    }
}
