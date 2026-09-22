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

/*
 * Router for the PHP built-in server used by PreviewServiceTest. Kept apart
 * from router.php next to it: MaterializationServiceTest relies on every
 * rendition path answering 404, and serving a real image there would change
 * what its rebuilds find on disk.
 */

$path = parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

// The full path, not the basename: a prefetch keyed differently from the
// path that is later read requests the same file under another address, and
// the only trace of it is the line it writes here. The built-in server
// re-runs this script per request, so nothing in process memory survives.
file_put_contents(
    sys_get_temp_dir().'/typo3-file-sync-preview-hits.log',
    $path.\PHP_EOL,
    \FILE_APPEND | \LOCK_EX,
);

if ('/fileadmin/_processed_/csm_provisional_small.jpg' === $path) {
    $image = imagecreatetruecolor(150, 100);
    $color = imagecolorallocate($image, 200, 120, 40) ?: 0;
    imagefilledrectangle($image, 0, 0, 149, 99, $color);
    header('Content-Type: image/jpeg');
    imagejpeg($image, null, 80);

    return;
}

if ('/fileadmin/_processed_/csm_oversized_small.jpg' === $path) {
    // Far past PreviewGenerator::MAX_BYTES, and sent in chunks so that the
    // server does not hold it either. A remote instance is not obliged to
    // keep its _processed_ folder small, and nothing in the local database
    // records how large a rendition on the other side is.
    header('Content-Type: image/jpeg');
    for ($chunk = 0; $chunk < 32; ++$chunk) {
        echo str_repeat('x', 1_048_576);
    }

    return;
}

if ('/fileadmin/_processed_/csm_fallback.jpg' === $path) {
    // A remote instance answering 200 with something that is not an image is
    // the ordinary shape of a login page or an error document.
    header('Content-Type: text/html');
    echo '<!DOCTYPE html><html lang="en"><body>not an image</body></html>';

    return;
}

http_response_code(404);
echo 'not found';
