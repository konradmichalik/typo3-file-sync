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

namespace KonradMichalik\Typo3FileSync;

use KonradMichalik\Typo3FileSync\Resource\Handler\{PlaceholderImageResource, RemoteInstanceResource};
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;

use function is_array;

/**
 * Configuration.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class Configuration
{
    public const EXT_KEY = 'typo3_file_sync';

    public const EXT_NAME = 'Typo3FileSync';

    public const EXTCONF_RESOURCE_HANDLER = 'resourceHandler';

    public const EXTCONF_STORAGES = 'storages';

    public const EXTCONF_DEFERRED_STORAGES = 'deferredStorages';

    public const FEATURE_DEFERRED_LOADING = 'fileSync.deferredLoading';

    public const FEATURE_PREVIEW_IMAGES = 'fileSync.previewImages';

    public const FIELD_ENABLE = 'tx_typo3_file_sync_enable';

    public const FIELD_DEFERRED = 'tx_typo3_file_sync_deferred';

    public const FIELD_RESOURCES = 'tx_typo3_file_sync_resources';

    public const FIELD_IDENTIFIER = 'tx_typo3_file_sync_identifier';

    public const FIELD_TSTAMP = 'tx_typo3_file_sync_tstamp';

    /**
     * When an on-demand fetch last failed for a file. Kept apart from
     * FIELD_TSTAMP because that one records when a handler delivered, which
     * is what the backend shows next to the sync badge.
     */
    public const FIELD_FAILED = 'tx_typo3_file_sync_failed';

    /**
     * The built-in handlers merged with EXTCONF. Read this instead of EXTCONF
     * alone: a context that boots the container without loading
     * ext_localconf.php (the Install Tool's test mail, for one) would see no
     * handlers at all.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getResourceHandlers(): array
    {
        $registered = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][self::EXT_KEY][self::EXTCONF_RESOURCE_HANDLER] ?? [];

        return array_merge(
            [
                ResourceIdentifier::RemoteInstance->value => [
                    'title' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.remote_instance',
                    'config' => [
                        'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.url',
                        'config' => [
                            'type' => 'input',
                            'required' => true,
                        ],
                    ],
                    'handler' => RemoteInstanceResource::class,
                ],
                ResourceIdentifier::PlaceholderImage->value => [
                    'title' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.placeholder_image',
                    'config' => [
                        'label' => 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.colors',
                        'config' => [
                            'type' => 'input',
                            'default' => '#CCCCCC, #969696',
                            'required' => true,
                        ],
                    ],
                    'handler' => PlaceholderImageResource::class,
                ],
            ],
            is_array($registered) ? $registered : [],
        );
    }
}
