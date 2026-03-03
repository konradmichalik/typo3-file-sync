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

namespace KonradMichalik\Typo3FileSync\Hooks;

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use TYPO3\CMS\Core\Utility\MathUtility;

final class DeleteFiles
{
    public function __construct(protected readonly FileRepository $fileRepository) {}

    /**
     * @param string|int $id
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id): void
    {
        if ($table !== 'sys_file_storage'
            || empty($_POST['_save_tx_typo3_file_sync_delete'])
            || !MathUtility::canBeInterpretedAsInteger($id)
        ) {
            return;
        }

        $this->fileRepository->deleteByIdentifier($_POST['_save_tx_typo3_file_sync_delete'], (int)$id);
    }
}
