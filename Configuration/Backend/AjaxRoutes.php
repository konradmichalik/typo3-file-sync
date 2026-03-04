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

use KonradMichalik\Typo3FileSync\Controller\StorageController;

return [
    'file_sync_reset_missing' => [
        'path' => '/file-sync/reset-missing',
        'methods' => ['POST'],
        'target' => StorageController::class.'::resetMissingAction',
    ],
    'file_sync_delete_files' => [
        'path' => '/file-sync/delete-files',
        'methods' => ['POST'],
        'target' => StorageController::class.'::deleteFilesAction',
    ],
];
