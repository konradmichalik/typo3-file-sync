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

namespace KonradMichalik\Typo3FileSync\Tests;

use function pack;
use function strlen;

/**
 * StoredPreview.
 *
 * Bytes PreviewStore accepts as a preview, for the four test classes that
 * need one in the store without caring what it is a picture of.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
trait StoredPreview
{
    /**
     * A RIFF container with the length field a real encoder would write,
     * which is all the store inspects: it reads a short-written file as
     * absent, so nothing shorter would come back out again.
     *
     * Built rather than encoded with GD for two reasons. The payload stays
     * readable in a failure message, and no generator would ever produce
     * it, so a test asserting on these exact bytes cannot pass because
     * something fetched and re-encoded a real picture instead of reading
     * the store.
     */
    private static function webp(string $payload): string
    {
        return 'RIFF'.pack('V', 4 + strlen($payload)).'WEBP'.$payload;
    }
}
