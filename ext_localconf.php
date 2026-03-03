<?php

defined('TYPO3') || die('Access denied.');

call_user_func(static function (): void {
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['typo3_file_sync_missing'] =
        \KonradMichalik\Typo3FileSync\Hooks\ResetMissingFiles::class;

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['typo3_file_sync_delete'] =
        \KonradMichalik\Typo3FileSync\Hooks\DeleteFiles::class;

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1700000001] = [
        'nodeName' => 'showMissingFiles',
        'priority' => 40,
        'class' => \KonradMichalik\Typo3FileSync\Form\Element\ShowMissingFiles::class,
    ];

    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1700000002] = [
        'nodeName' => 'showDeleteFiles',
        'priority' => 40,
        'class' => \KonradMichalik\Typo3FileSync\Form\Element\ShowDeleteFiles::class,
    ];

    if (empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'])) {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = [];
    }
    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = array_merge(
        [
            'remote_instance' => [
                'title' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.remote_instance',
                'config' => [
                    'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.url',
                    'config' => [
                        'type' => 'input',
                        'eval' => 'required',
                    ],
                ],
                'handler' => \KonradMichalik\Typo3FileSync\Resource\Handler\RemoteInstanceResource::class,
            ],
            'placeholder_image' => [
                'title' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.placeholder_image',
                'config' => [
                    'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.colors',
                    'config' => [
                        'type' => 'input',
                        'eval' => 'required',
                        'default' => '#CCCCCC, #969696',
                    ],
                ],
                'handler' => \KonradMichalik\Typo3FileSync\Resource\Handler\PlaceholderImageResource::class,
            ],
        ],
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler']
    );
});
