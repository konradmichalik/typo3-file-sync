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

namespace KonradMichalik\Typo3FileSync\Resource\Preview;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;
use function is_file;

/**
 * PreviewStore.
 *
 * A filesystem-backed store for tiny blurred WebP previews, one per file.
 * It lives under var/ rather than in the database because the filesystem
 * is precisely what a production sync does not overwrite, which makes a
 * preview a one-time cost per file instead of a cost per sync.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class PreviewStore
{
    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? Environment::getVarPath().'/file-sync/previews', '/');
    }

    public function has(int $storageUid, string $fileIdentifier): bool
    {
        return is_file($this->path($storageUid, $fileIdentifier));
    }

    public function read(int $storageUid, string $fileIdentifier): ?string
    {
        $path = $this->path($storageUid, $fileIdentifier);

        return is_file($path) ? (file_get_contents($path) ?: null) : null;
    }

    public function write(int $storageUid, string $fileIdentifier, string $webp): void
    {
        $path = $this->path($storageUid, $fileIdentifier);
        GeneralUtility::mkdir_deep(dirname($path));
        GeneralUtility::writeFile($path, $webp, true);
    }

    public function remove(int $storageUid, string $fileIdentifier): void
    {
        $path = $this->path($storageUid, $fileIdentifier);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function path(int $storageUid, string $fileIdentifier): string
    {
        $hash = hash('sha256', $storageUid.':'.$fileIdentifier);

        // Two-character subdirectory keeps a large installation from collecting
        // ten thousand entries in one directory.
        return $this->basePath.'/'.substr($hash, 0, 2).'/'.$hash.'.webp';
    }
}
