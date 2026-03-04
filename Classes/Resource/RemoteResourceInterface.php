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

use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * RemoteResourceInterface.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
interface RemoteResourceInterface
{
    public function hasFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): bool;

    /**
     * @return resource|string|false
     */
    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): mixed;
}
