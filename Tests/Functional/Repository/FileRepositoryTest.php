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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Repository;

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * FileRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(FileRepository::class)]
final class FileRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    private FileRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file.csv');
        $this->subject = $this->get(FileRepository::class);
    }

    #[Test]
    public function countByIdentifierGroupsRowsByIdentifierFieldExcludingEmpty(): void
    {
        $result = $this->subject->countByIdentifier();

        self::assertCount(1, $result);
        self::assertSame(1, (int) $result[0]['count']);
        self::assertSame('/synced/baz.jpg', $result[0]['tx_typo3_file_sync_identifier']);
    }

    #[Test]
    public function countByIdentifierFiltersByStorage(): void
    {
        self::assertCount(0, $this->subject->countByIdentifier(2));
    }

    #[Test]
    public function findSyncDataReturnsIdentifierAndTimestampForKnownFile(): void
    {
        $result = $this->subject->findSyncData(2);

        self::assertSame('/synced/baz.jpg', $result['identifier']);
        self::assertSame(1700000000, $result['tstamp']);
    }

    #[Test]
    public function findSyncDataReturnsEmptyDefaultsForUnknownFile(): void
    {
        self::assertSame(['identifier' => '', 'tstamp' => 0], $this->subject->findSyncData(9999));
    }

    #[Test]
    public function countMissingCountsOnlyMissingFilesForGivenStorage(): void
    {
        self::assertSame(1, $this->subject->countMissing(1));
        self::assertSame(1, $this->subject->countMissing(2));
    }

    #[Test]
    public function resetMissingClearsMissingFlagAndReturnsAffectedRowCount(): void
    {
        self::assertSame(1, $this->subject->resetMissing(1));
        self::assertSame(0, $this->subject->countMissing(1));
    }
}
