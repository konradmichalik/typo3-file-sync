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

use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit;

ExtensionManagementUtility::addTypoScript(
    'sitepackage',
    'setup',
    '@import "EXT:sitepackage/Configuration/TypoScript/setup.typoscript"',
);

// Configure file sync on default storage (uid=1):
// 1. Fetch from fake remote server (served by DDEV)
// 2. Fall back to placeholder images if remote is unavailable
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'][1] = [
    [
        'identifier' => ResourceIdentifier::RemoteInstance->value,
        'configuration' => 'http://remote.typo3-file-sync.ddev.site',
    ],
    [
        'identifier' => ResourceIdentifier::PlaceholderImage->value,
        'configuration' => '#CCCCCC, #969696',
    ],
];

// Enable debug logging for file sync operations
$GLOBALS['TYPO3_CONF_VARS']['LOG']['KonradMichalik']['Typo3FileSync']['writerConfiguration'] = [
    LogLevel::DEBUG => [
        FileWriter::class => [
            'logFile' => TYPO3\CMS\Core\Core\Environment::getVarPath().'/log/typo3_file_sync.log',
        ],
    ],
];
