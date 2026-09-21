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
 * Router for the PHP built-in server used by RemoteInstanceResourceTest.
 * Emulates the response variants a remote TYPO3 instance can deliver.
 */

$path = parse_url((string) $_SERVER['REQUEST_URI'], \PHP_URL_PATH);

switch ($path) {
    case '/fileadmin/plain.jpg':
        header('Content-Type: image/jpeg');
        echo str_repeat('A', 2048);
        break;

    case '/fileadmin/redirect.jpg':
        header('Location: /fileadmin/plain.jpg', true, 302);
        break;

    case '/fileadmin/redirect-chain.jpg':
        header('Location: /fileadmin/redirect.jpg', true, 301);
        break;

    case '/fileadmin/gzip.jpg':
        header('Content-Type: image/jpeg');
        header('Content-Encoding: gzip');
        echo gzencode(str_repeat('A', 2048));
        break;

    case '/fileadmin/batch-1.jpg':
    case '/fileadmin/batch-2.jpg':
    case '/fileadmin/batch-3.jpg':
        // Recorded so a test can prove a prefetched path was fetched exactly
        // once, not merely that its body ended up in the right place: the
        // built-in server re-runs this script per request, so nothing in
        // process memory survives between requests.
        file_put_contents(
            sys_get_temp_dir().'/typo3-file-sync-batch-hits.log',
            basename($path).\PHP_EOL,
            \FILE_APPEND | \LOCK_EX,
        );
        header('Content-Type: image/jpeg');
        echo 'body-for-'.basename($path);
        break;

    default:
        http_response_code(404);
        echo 'not found';
}
