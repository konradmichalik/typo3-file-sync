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

return [
    'dependencies' => ['backend'],
    'imports' => [
        '@konradmichalik/typo3-file-sync/' => 'EXT:typo3_file_sync/Resources/Public/JavaScript/',
    ],
];
