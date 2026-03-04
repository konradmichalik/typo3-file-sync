<?php

declare(strict_types=1);

use KonradMichalik\Typo3FileSync\Resource\Handler\PlaceholderImageResource;
use KonradMichalik\Typo3FileSync\Resource\Handler\RemoteInstanceResource;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTypoScript(
    'sitepackage',
    'setup',
    '@import "EXT:sitepackage/Configuration/TypoScript/setup.typoscript"'
);

// Configure file sync on default storage (uid=1):
// 1. Fetch from fake remote server (served by DDEV)
// 2. Fall back to placeholder images if remote is unavailable
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'][1] = [
    [
        'identifier' => 'remote_instance',
        'configuration' => 'http://remote.typo3-file-sync.ddev.site',
    ],
    [
        'identifier' => 'placeholder_image',
        'configuration' => '#CCCCCC, #969696',
    ],
];
