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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\EventListener;

use KonradMichalik\Typo3FileSync\EventListener\FileProcessingEventEventListener;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\AbstractFile;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Event\BeforeFileProcessingEvent;
use TYPO3\CMS\Core\Resource\{FileInterface, ProcessedFile};

/**
 * FileProcessingEventEventListenerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(FileProcessingEventEventListener::class)]
final class FileProcessingEventEventListenerTest extends TestCase
{
    #[Test]
    public function invokeChecksExistenceOfProcessedFileAndAbstractFile(): void
    {
        $processedFile = $this->createMock(ProcessedFile::class);
        $processedFile->expects(self::once())->method('exists');

        $file = $this->createMock(AbstractFile::class);
        $file->expects(self::once())->method('exists');

        $event = new BeforeFileProcessingEvent(
            $this->createMock(DriverInterface::class),
            $processedFile,
            $file,
            'Preview',
            [],
        );

        (new FileProcessingEventEventListener())($event);
    }

    #[Test]
    public function invokeSkipsExistenceCheckForNonAbstractFile(): void
    {
        $processedFile = $this->createMock(ProcessedFile::class);
        $processedFile->expects(self::once())->method('exists');

        $file = $this->createMock(FileInterface::class);

        $event = new BeforeFileProcessingEvent(
            $this->createMock(DriverInterface::class),
            $processedFile,
            $file,
            'Preview',
            [],
        );

        (new FileProcessingEventEventListener())($event);
    }
}
