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
    'tx_typo3_file_sync_enable' => [
        'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.enable',
        'displayCond' => 'FIELD:driver:=:Local',
        'config' => [
            'type' => 'check',
            'default' => 0,
        ],
    ],
    'tx_typo3_file_sync_resources' => [
        'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.resources',
        'displayCond' => 'FIELD:driver:=:Local',
        'config' => [
            'type' => 'flex',
            'ds' => [
                'default' => 'FILE:EXT:typo3_file_sync/Configuration/FlexForms/Resources.xml',
            ],
        ],
    ],
    'tx_typo3_file_sync_missing' => [
        'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.missing',
        'displayCond' => 'FIELD:driver:=:Local',
        'config' => [
            'type' => 'user',
            'renderType' => 'showMissingFiles',
        ],
    ],
    'tx_typo3_file_sync_delete' => [
        'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.delete',
        'displayCond' => 'FIELD:driver:=:Local',
        'config' => [
            'type' => 'user',
            'renderType' => 'showDeleteFiles',
        ],
    ],
];

TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('sys_file_storage', $tempColumns);
TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'sys_file_storage',
    '--div--;LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync,'
    .'tx_typo3_file_sync_enable, tx_typo3_file_sync_resources, tx_typo3_file_sync_missing, tx_typo3_file_sync_delete',
    '',
    'after:processingfolder',
);
