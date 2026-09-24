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

use Doctrine\DBAL\{ArrayParameterType, ParameterType};
use KonradMichalik\Typo3FileSync\Configuration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * StorageService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class StorageService
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array<int, array{uid: int, name: string}>
     */
    public function getEnabledStorages(): array
    {
        $configuredStorages = array_keys($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES] ?? []);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $expressionBuilder = $queryBuilder->expr();
        $rows = $queryBuilder->select('uid', 'name')
            ->from('sys_file_storage')
            ->where(
                $expressionBuilder->or(
                    $expressionBuilder->eq(
                        Configuration::FIELD_ENABLE,
                        $queryBuilder->createNamedParameter(1, ParameterType::INTEGER),
                    ),
                    $expressionBuilder->in(
                        'uid',
                        $queryBuilder->createNamedParameter($configuredStorages, ArrayParameterType::INTEGER),
                    ),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        /** @var array<int, array{uid: int, name: string}> $result */
        $result = array_combine(array_map(intval(...), array_column($rows, 'uid')), $rows);

        return $result;
    }

    /**
     * Read from the storage records rather than from FetchMode, because a
     * page answered out of the page cache never initialises a storage and
     * that is exactly the request where deferred images matter.
     *
     * @return list<int>
     */
    public function getDeferredStorageUids(): array
    {
        // A storage can be switched on by its record or by EXTCONF, and the
        // storage initialisation listener honours the deferred field either
        // way. Filtering on the record flag alone would leave an EXTCONF
        // storage deferring its render while nothing ever marks its images.
        $configuredStorages = array_keys($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES] ?? []);

        // Same reasoning for the deferred flag itself: ResourceStorageInitializationEventListener
        // honours deferredStorages as an alternative to the record flag, so this lookup has to
        // as well, otherwise a storage deferring its render this way would leave every image
        // stuck on the placeholder, since nothing would ever mark them for the swap.
        $deferredStorages = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_DEFERRED_STORAGES] ?? [];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_storage');
        $expressionBuilder = $queryBuilder->expr();
        $rows = $queryBuilder->select('uid')
            ->from('sys_file_storage')
            ->where(
                $expressionBuilder->or(
                    $expressionBuilder->eq(
                        Configuration::FIELD_ENABLE,
                        $queryBuilder->createNamedParameter(1, ParameterType::INTEGER),
                    ),
                    $expressionBuilder->in(
                        'uid',
                        $queryBuilder->createNamedParameter($configuredStorages, ArrayParameterType::INTEGER),
                    ),
                ),
                $expressionBuilder->or(
                    $expressionBuilder->eq(
                        Configuration::FIELD_DEFERRED,
                        $queryBuilder->createNamedParameter(1, ParameterType::INTEGER),
                    ),
                    $expressionBuilder->in(
                        'uid',
                        $queryBuilder->createNamedParameter($deferredStorages, ArrayParameterType::INTEGER),
                    ),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(intval(...), array_column($rows, 'uid'));
    }
}
