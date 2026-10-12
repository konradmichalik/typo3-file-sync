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
 * Marks a handler that makes up a stand-in instead of delivering the real
 * file. A bulk fetch skips these unless asked for them by name, otherwise a
 * file the remote cannot deliver would be hidden behind a placeholder.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
interface GeneratingResourceInterface {}
