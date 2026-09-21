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
 * BatchRemoteResourceInterface.
 *
 * Lets a handler download several files concurrently ahead of the serial
 * calls FAL makes, one file at a time.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
interface BatchRemoteResourceInterface
{
    /**
     * @param list<string> $filePaths
     */
    public function prefetch(array $filePaths): void;
}
