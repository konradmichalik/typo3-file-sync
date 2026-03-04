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

/**
 * Configuration.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Configuration
{
    public const EXT_KEY = 'typo3_file_sync';

    public const EXT_NAME = 'Typo3FileSync';

    public const EXTCONF_RESOURCE_HANDLER = 'resourceHandler';

    public const EXTCONF_STORAGES = 'storages';

    public const FIELD_ENABLE = 'tx_typo3_file_sync_enable';

    public const FIELD_RESOURCES = 'tx_typo3_file_sync_resources';

    public const FIELD_IDENTIFIER = 'tx_typo3_file_sync_identifier';

    public const FIELD_TSTAMP = 'tx_typo3_file_sync_tstamp';
}
