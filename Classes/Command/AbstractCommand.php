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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use KonradMichalik\Typo3FileSync\Configuration;
use Symfony\Component\Console\Command\Command;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractCommand extends Command
{
    /**
     * @return array<int, array{uid: int, name: string}>
     */
    protected function getEnabledStorages(): array
    {
        $configuredStorages = array_keys($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['storages'] ?? ['0' => '']);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_storage');
        $expressionBuilder = $queryBuilder->expr();
        $rows = $queryBuilder->select('uid', 'name')
            ->from('sys_file_storage')
            ->where(
                $expressionBuilder->or(
                    $expressionBuilder->eq(
                        'tx_typo3_file_sync_enable',
                        $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)
                    ),
                    $expressionBuilder->in(
                        'uid',
                        $queryBuilder->createNamedParameter($configuredStorages, ArrayParameterType::INTEGER)
                    )
                )
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_combine(array_map('intval', array_column($rows, 'uid')), $rows);
    }
}
