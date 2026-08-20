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

    default:
        http_response_code(404);
        echo 'not found';
}
