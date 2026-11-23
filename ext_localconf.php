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
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][KonradMichalik\Typo3FileSync\Configuration::FEATURE_DEFERRED_LOADING] ??= false;
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][KonradMichalik\Typo3FileSync\Configuration::FEATURE_PREVIEW_IMAGES] ??= false;

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

    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = KonradMichalik\Typo3FileSync\Configuration::getResourceHandlers();
});
