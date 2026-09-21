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

/**
 * DeferrableResourceInterface.
 *
 * Marks a handler a deferred render skips, so that a page goes out without
 * waiting on the network.
 *
 * Implementing this outside this extension is not supported. FileRepository
 * decides what counts as provisional at the database level, where it can only
 * compare against the one identifier it knows, while MaterializationService
 * accepts every identifier marked here. The two definitions agree only while
 * RemoteInstanceResource is the sole implementor. A second one is called
 * delivered by the service while the repository still counts the file
 * provisional, and the next render defers it again, indefinitely.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
interface DeferrableResourceInterface {}
