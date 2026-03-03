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

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

final class ResetMissingFiles
{
    /**
     * @param string|int $id
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id): void
    {
        if ($table !== 'sys_file_storage'
            || empty($_POST['_save_tx_typo3_file_sync_missing'])
            || !MathUtility::canBeInterpretedAsInteger($id)
        ) {
            return;
        }
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $expressionBuilder = $queryBuilder->expr();
        $queryBuilder->update('sys_file')
            ->where(
                $expressionBuilder->eq(
                    'storage',
                    $queryBuilder->createNamedParameter((int)$id, ParameterType::INTEGER)
                )
            )
            ->set('missing', 0)
            ->executeStatement();
    }
}
