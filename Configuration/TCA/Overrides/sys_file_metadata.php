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

defined('TYPO3') || exit('Access denied.');

$tempColumns = [
    'tx_typo3_file_sync_status' => [
        'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_metadata.file_sync.status',
        'config' => [
            'type' => 'user',
            'renderType' => 'showSyncStatus',
        ],
    ],
];

TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('sys_file_metadata', $tempColumns);

TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'sys_file_metadata',
    'tx_typo3_file_sync_status',
    '',
    'after:fileinfo',
);
