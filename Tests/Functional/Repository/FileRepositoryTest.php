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

    #[Test]
    public function findByIdentifierReturnsMatchingStorageAndIdentifierPairs(): void
    {
        // uid 2's tx_typo3_file_sync_identifier is '/synced/baz.jpg'; the SELECT
        // returns sys_file.identifier (the file path column, '/foo/baz.jpg'), not
        // the search key itself — see Classes/Repository/FileRepository.php's
        // findByIdentifier() select('storage', 'identifier').
        $result = $this->subject->findByIdentifier('/synced/baz.jpg');

        self::assertSame([['storage' => 1, 'identifier' => '/foo/baz.jpg']], $result);
    }

    #[Test]
    public function findByIdentifierReturnsEmptyArrayForUnknownIdentifier(): void
    {
        self::assertSame([], $this->subject->findByIdentifier('/does/not/exist.jpg'));
    }

    #[Test]
    public function deleteByIdentifierReturnsCountOfMatchingRowsRegardlessOfFilesystemState(): void
    {
        // uid 2 matches tx_typo3_file_sync_identifier='/synced/baz.jpg' AND storage=1.
        // No sys_file_storage fixture is imported anywhere in this test class, so
        // storageRepository->getStorageObject(1) throws InvalidArgumentException
        // internally (StorageRepository::fetchRecordDataByUid() — "No storage found
        // with uid \"1\"."), which deleteByIdentifier()'s loop catches and continues.
        // deleteByIdentifier() returns count($rows) unconditionally, so this still
        // returns 1 despite the lookup failure.
        $count = $this->subject->deleteByIdentifier('/synced/baz.jpg', 1);

        self::assertSame(1, $count);
    }

    #[Test]
    public function findSyncDataByUidsReturnsIdentifierAndTimestampKeyedByUid(): void
    {
        $result = $this->subject->findSyncDataByUids([1, 2]);

        self::assertSame([
            1 => ['identifier' => '', 'tstamp' => 0],
            2 => ['identifier' => '/synced/baz.jpg', 'tstamp' => 1700000000],
        ], $result);
    }

    #[Test]
    public function findSyncDataByUidsReturnsEmptyArrayForAnEmptyUidList(): void
    {
        self::assertSame([], $this->subject->findSyncDataByUids([]));
    }

    #[Test]
    public function findProcessedFilesByUidsKeysRowsByUid(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findProcessedFilesByUids([110, 112]);

        self::assertSame([110, 112], array_keys($result));
        self::assertSame(101, (int) $result[110]['original']);
        self::assertSame('Image.CropScaleMask', $result[110]['task_type']);
    }

    #[Test]
    public function findProcessedFilesByUidsReturnsEmptyArrayForAnEmptyUidList(): void
    {
        self::assertSame([], $this->subject->findProcessedFilesByUids([]));
    }

    #[Test]
    public function findProcessedFilesByUidsCarriesTheRenditionDimensions(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findProcessedFilesByUids([110]);

        self::assertSame(300, (int) $result[110]['width']);
        self::assertSame(200, (int) $result[110]['height']);
    }

    #[Test]
    public function findLocationsByUidsKeysStorageAndIdentifierByUid(): void
    {
        $result = $this->subject->findLocationsByUids([1, 3]);

        self::assertSame(
            [
                1 => ['storage' => 1, 'identifier' => '/foo/bar.jpg'],
                3 => ['storage' => 2, 'identifier' => '/other/file.jpg'],
            ],
            $result,
        );
    }

    #[Test]
    public function findLocationsByUidsReturnsEmptyArrayForAnEmptyUidList(): void
    {
        self::assertSame([], $this->subject->findLocationsByUids([]));
    }

    #[Test]
    public function touchSyncTimestampStampsTheTimestampAndLeavesTheIdentifierAlone(): void
    {
        $this->subject->touchSyncTimestamp(2);

        $result = $this->subject->findSyncData(2);

        self::assertSame('/synced/baz.jpg', $result['identifier']);
        self::assertGreaterThan(1700000000, $result['tstamp']);
    }

    #[Test]
    public function countProvisionalCountsOnlyFilesDeliveredByAFallbackHandler(): void
    {
        // setUp() already imports Fixtures/sys_file.csv, whose uid 2 also carries a
        // non-empty, non-remote-instance identifier and therefore counts as
        // provisional too. Assert the delta so this test does not depend on that
        // unrelated fixture's contents.
        $baselineCount = $this->subject->countProvisional([1]);

        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame($baselineCount + 1, $this->subject->countProvisional([1]));
    }

    #[Test]
    public function findProvisionalProcessedFilesMapsIdentifiersToUids(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findProvisionalProcessedFiles([1], [
            '/_processed_/a/b/csm_provisional_aaa.jpg',
            '/_processed_/a/b/csm_real_bbb.jpg',
            '/_processed_/a/b/csm_untouched_ccc.jpg',
        ]);

        self::assertSame(['/_processed_/a/b/csm_provisional_aaa.jpg' => 110], $result);
    }

    #[Test]
    public function findProvisionalProcessedFilesReturnsEmptyArrayForAnEmptyIdentifierList(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame([], $this->subject->findProvisionalProcessedFiles([1], []));
    }

    #[Test]
    public function findSmallestRenditionPrefersTheBackendThumbnail(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findSmallestRendition(101);

        self::assertSame('/_processed_/a/b/csm_provisional_thumb.jpg', $result['identifier']);
        self::assertSame(1, $result['storage']);
    }

    #[Test]
    public function findSmallestRenditionFallsBackToTheNarrowestRendition(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame(
            '/_processed_/a/b/csm_real_bbb.jpg',
            $this->subject->findSmallestRendition(102)['identifier'],
        );
    }

    #[Test]
    public function findSmallestRenditionIgnoresRowsWithoutDimensions(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame(
            '/_processed_/a/b/csm_untouched_ccc.jpg',
            $this->subject->findSmallestRendition(103)['identifier'],
        );
    }

    #[Test]
    public function findSmallestRenditionReturnsNullWhenNoneExists(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertNull($this->subject->findSmallestRendition(999));
    }
}
