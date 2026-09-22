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

namespace KonradMichalik\Typo3FileSync\Service;

use function basename;
use function dirname;
use function is_file;
use function rename;
use function unlink;

/**
 * StashedFile.
 *
 * A file moved out of the way so that something may try to replace it, and
 * put back when that attempt fails. Moved rather than copied, because a
 * rename costs nothing whatever the file weighs, while reading the bytes
 * into memory first would cost the whole file.
 *
 * The stash keeps the file in its own directory under a dot prefixed name,
 * which is the one place a rename is guaranteed not to cross a filesystem
 * boundary, and the prefix keeps it out of FAL's sight: core filters dot
 * files out of every folder listing.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class StashedFile
{
    private function __construct(
        private string $path,
        private string $stashPath,
    ) {}

    /**
     * @return self|null null when the file could not be moved, which means
     *                   the caller must leave it exactly where it is
     */
    public static function stash(string $path): ?self
    {
        $stashPath = dirname($path).'/.tx-file-sync-stash-'.basename($path);

        return rename($path, $stashPath) ? new self($path, $stashPath) : null;
    }

    /**
     * Overwrites whatever the failed attempt left behind. What was on disk
     * before is the better of the two, because the already cached HTML still
     * points at it.
     */
    public function restore(): void
    {
        if (is_file($this->stashPath)) {
            rename($this->stashPath, $this->path);
        }
    }

    public function discard(): void
    {
        if (is_file($this->stashPath)) {
            unlink($this->stashPath);
        }
    }
}
