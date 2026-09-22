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

use KonradMichalik\Typo3FileSync\Middleware\{DeferredImageMiddleware, MaterializeMiddleware};

return [
    'frontend' => [
        'konradmichalik/typo3-file-sync/deferred-images' => [
            'target' => DeferredImageMiddleware::class,
            'before' => ['typo3/cms-frontend/timetracker'],
        ],
        'konradmichalik/typo3-file-sync/materialize' => [
            'target' => MaterializeMiddleware::class,
            'after' => ['typo3/cms-frontend/timetracker'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
