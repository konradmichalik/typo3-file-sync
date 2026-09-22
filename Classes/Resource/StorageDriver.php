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

namespace KonradMichalik\Typo3FileSync\Resource;

use Closure;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * StorageDriver.
 *
 * Reaching into a private core property is fragile enough that it belongs in
 * exactly one place: a future core change fixed in one copy and missed in
 * another would fail silently.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class StorageDriver
{
    /**
     * TYPO3 core deliberately keeps the driver private with no public accessor.
     *
     * @see ResourceStorage::$driver (private)
     * @see ResourceStorage::getDriver() (protected)
     */
    public static function extract(ResourceStorage $storage): DriverInterface
    {
        return Closure::bind(static fn () => $storage->driver, null, ResourceStorage::class)();
    }
}
