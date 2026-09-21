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
    header('Content-Type: image/jpeg');
    echo 'remote-body';

    return;
}

http_response_code(404);
echo 'not found';
