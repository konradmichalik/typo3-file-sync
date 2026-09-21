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
 * Router for the PHP built-in server used by MaterializationServiceTest.
 * Only the one original that is expected to materialize is served; every
 * other path answers 404 so the failure branch can be exercised.
 */

$path = parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

if ('/fileadmin/user_upload/provisional.jpg' === $path) {
    // Recorded so a test can prove one original is fetched once no matter
    // how many renditions of it a batch asks for. The built-in server
    // re-runs this script per request, so nothing in process memory
    // survives between requests.
    file_put_contents(
        sys_get_temp_dir().'/typo3-file-sync-materialize-hits.log',
        basename((string) $path).\PHP_EOL,
        \FILE_APPEND | \LOCK_EX,
    );
    header('Content-Type: image/jpeg');
    echo 'remote-body';

    return;
}

http_response_code(404);
echo 'not found';
