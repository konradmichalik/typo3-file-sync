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

use RuntimeException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function dirname;
use function is_file;
use function strlen;
use function substr;
use function unpack;

/**
 * PreviewStore.
 *
 * A filesystem-backed store for tiny blurred WebP previews, one per file.
 * It lives under var/ rather than in the database because the filesystem
 * is precisely what a production sync does not overwrite, which makes a
 * preview a one-time cost per file instead of a cost per sync.
 *
 * Not everything kept here is a picture: PreviewService parks its failure
 * markers under keys of their own, and a marker holds a timestamp. That is
 * why reading one has its own method, and why only read() is entitled to
 * expect WebP.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class PreviewStore
{
    /**
     * "RIFF", the payload length, "WEBP". Nothing shorter can be a WebP,
     * and the length is what makes a short write recognisable.
     */
    private const HEADER_BYTES = 12;

    public function has(int $storageUid, string $fileIdentifier): bool
    {
        return is_file($this->path($storageUid, $fileIdentifier));
    }

    /**
     * The stored preview, or null when what is there is not a complete one.
     *
     * GeneralUtility::writeFile() is not atomic, so a full disk or a killed
     * process leaves a truncated file behind. It is non-empty, and nothing
     * ever rewrites a key the store already holds, so a caller that took it
     * for a preview would serve those bytes for the lifetime of the store.
     */
    public function read(int $storageUid, string $fileIdentifier): ?string
    {
        $contents = $this->contents($storageUid, $fileIdentifier);

        return null !== $contents && self::isCompleteWebP($contents) ? $contents : null;
    }

    /**
     * The raw contents of a key that holds something other than a picture.
     */
    public function readMarker(int $storageUid, string $markerIdentifier): ?string
    {
        return $this->contents($storageUid, $markerIdentifier);
    }

    /**
     * A write that did not happen has to say so. Silence here means the
     * caller logs nothing, keeps answering from a store that never grows,
     * and every visitor pays the fetch the store exists to avoid.
     *
     * @throws RuntimeException when the preview could not be written
     */
    public function write(int $storageUid, string $fileIdentifier, string $webp): void
    {
        $path = $this->path($storageUid, $fileIdentifier);
        GeneralUtility::mkdir_deep(dirname($path));
        if (!GeneralUtility::writeFile($path, $webp, true)) {
            throw new RuntimeException('Preview could not be written to "'.$path.'".', 1790035200);
        }
    }

    public function remove(int $storageUid, string $fileIdentifier): void
    {
        $path = $this->path($storageUid, $fileIdentifier);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function contents(int $storageUid, string $fileIdentifier): ?string
    {
        $path = $this->path($storageUid, $fileIdentifier);

        return is_file($path) ? (file_get_contents($path) ?: null) : null;
    }

    /**
     * A RIFF container states its own payload length, so a file that was
     * written short says so itself rather than merely looking suspicious.
     * Compared with "at least" rather than "exactly", because trailing
     * bytes an encoder padded with are not the failure in question.
     */
    private static function isCompleteWebP(string $contents): bool
    {
        if (strlen($contents) < self::HEADER_BYTES
            || 'RIFF' !== substr($contents, 0, 4)
            || 'WEBP' !== substr($contents, 8, 4)
        ) {
            return false;
        }

        $header = unpack('V', substr($contents, 4, 4));

        return false !== $header && strlen($contents) - 8 >= (int) $header[1];
    }

    private function path(int $storageUid, string $fileIdentifier): string
    {
        $hash = hash('sha256', $storageUid.':'.$fileIdentifier);

        // Two-character subdirectory keeps a large installation from collecting
        // ten thousand entries in one directory.
        return Environment::getVarPath().'/file-sync/previews/'.substr($hash, 0, 2).'/'.$hash.'.webp';
    }
}
