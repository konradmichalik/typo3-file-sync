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

namespace KonradMichalik\Typo3FileSync\EventListener;

use TYPO3\CMS\Core\Resource\AbstractFile;
use TYPO3\CMS\Core\Resource\Event\BeforeFileProcessingEvent;

/**
 * FileProcessingEventEventListener.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class FileProcessingEventEventListener
{
    public function __invoke(BeforeFileProcessingEvent $event): void
    {
        $processedFile = $event->getProcessedFile();
        $processedFile->exists();

        $file = $event->getFile();
        if ($file instanceof AbstractFile) {
            $file->exists();
        }
    }
}
