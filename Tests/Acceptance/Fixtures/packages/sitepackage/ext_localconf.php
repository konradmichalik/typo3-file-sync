<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTypoScript(
    'sitepackage',
    'setup',
    '@import "EXT:sitepackage/Configuration/TypoScript/setup.typoscript"'
);
