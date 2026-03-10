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

call_user_func(static function (): void {
    $nodeRegistry = &$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'];

    $nodeRegistry[1700000001] ??= [
        'nodeName' => 'fileSyncShowMissingFiles',
        'priority' => 40,
        'class' => KonradMichalik\Typo3FileSync\Form\Element\ShowMissingFiles::class,
    ];

    $nodeRegistry[1700000002] ??= [
        'nodeName' => 'fileSyncShowDeleteFiles',
        'priority' => 40,
        'class' => KonradMichalik\Typo3FileSync\Form\Element\ShowDeleteFiles::class,
    ];

    $nodeRegistry[1700000003] ??= [
        'nodeName' => 'fileSyncShowSyncStatus',
        'priority' => 40,
        'class' => KonradMichalik\Typo3FileSync\Form\Element\ShowSyncStatus::class,
    ];

    if (!isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'])
        || !is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'])
    ) {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = [];
    }
    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = array_merge(
        [
            KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier::RemoteInstance->value => [
                'title' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.remote_instance',
                'config' => [
                    'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.url',
                    'config' => [
                        'type' => 'input',
                        'required' => true,
                    ],
                ],
                'handler' => KonradMichalik\Typo3FileSync\Resource\Handler\RemoteInstanceResource::class,
            ],
            KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier::PlaceholderImage->value => [
                'title' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.placeholder_image',
                'config' => [
                    'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.colors',
                    'config' => [
                        'type' => 'input',
                        'default' => '#CCCCCC, #969696',
                        'required' => true,
                    ],
                ],
                'handler' => KonradMichalik\Typo3FileSync\Resource\Handler\PlaceholderImageResource::class,
            ],
        ],
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'],
    );
});
