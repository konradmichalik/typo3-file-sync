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

use function array_map;

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

    /**
     * The failure stamp rather than the sync timestamp, which are different
     * moments: uid 2 was delivered at 1700000000 and failed a fetch before
     * that, so a query reading the wrong column answers the wrong second.
     */
    #[Test]
    public function findSyncDataByUidsReturnsIdentifierAndFailureStampKeyedByUid(): void
    {
        $result = $this->subject->findSyncDataByUids([1, 2]);

        self::assertSame([
            1 => ['identifier' => '', 'failed' => 0],
            2 => ['identifier' => '/synced/baz.jpg', 'failed' => 1699999000],
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
    public function findProcessedFilesByUidsCarriesTheRenditionLocationAndDimensions(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findProcessedFilesByUids([110]);

        self::assertSame(1, (int) $result[110]['storage']);
        self::assertSame('/_processed_/a/b/csm_provisional_aaa.jpg', $result[110]['identifier']);
        self::assertSame(300, (int) $result[110]['width']);
        self::assertSame(200, (int) $result[110]['height']);
    }

    /**
     * The sync timestamp is what the backend shows as the moment a handler
     * delivered this file, so a failed fetch must leave it alone. Writing
     * the failure there is what made a placeholder render damp the very
     * request it was rendered for.
     */
    #[Test]
    public function markFetchFailureStampsItsOwnFieldAndLeavesTheSyncDataAlone(): void
    {
        $this->subject->markFetchFailure(2);

        $result = $this->subject->findSyncData(2);

        self::assertSame('/synced/baz.jpg', $result['identifier']);
        self::assertSame(1700000000, $result['tstamp']);
        self::assertGreaterThan(1700000000, $this->subject->findSyncDataByUids([2])[2]['failed']);
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

        self::assertSame(['/_processed_/a/b/csm_provisional_aaa.jpg' => ['uid' => 110, 'storage' => 1]], $result);
    }

    /**
     * The storage has to come off the rendition's own row rather than off the
     * queried list, because that pair is the key a stored preview lives under
     * and a caller passes every deferred storage at once.
     */
    #[Test]
    public function findProvisionalProcessedFilesReportsTheStorageEachRenditionActuallyLivesIn(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findProvisionalProcessedFiles([1, 2], [
            '/_processed_/a/b/csm_provisional_aaa.jpg',
            '/_processed_/c/d/csm_second_storage_ddd.jpg',
        ]);

        self::assertSame([
            '/_processed_/a/b/csm_provisional_aaa.jpg' => ['uid' => 110, 'storage' => 1],
            '/_processed_/c/d/csm_second_storage_ddd.jpg' => ['uid' => 118, 'storage' => 2],
        ], $result);
    }

    #[Test]
    public function findProvisionalProcessedFilesReturnsEmptyArrayForAnEmptyIdentifierList(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame([], $this->subject->findProvisionalProcessedFiles([1], []));
    }

    #[Test]
    public function findSmallestRenditionsPrefersTheBackendThumbnail(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findSmallestRenditions([101]);

        self::assertSame('/_processed_/a/b/csm_provisional_thumb.jpg', $result[101]['identifier']);
        self::assertSame(1, $result[101]['storage']);
    }

    #[Test]
    public function findSmallestRenditionsFallsBackToTheNarrowestRendition(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame(
            '/_processed_/a/b/csm_real_bbb.jpg',
            $this->subject->findSmallestRenditions([102])[102]['identifier'],
        );
    }

    #[Test]
    public function findSmallestRenditionsIgnoresRowsWithoutDimensions(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame(
            '/_processed_/a/b/csm_untouched_ccc.jpg',
            $this->subject->findSmallestRenditions([103])[103]['identifier'],
        );
    }

    /**
     * Ordering by width alone leaves a tie to the database, so the same
     * installation would take one rendition as its preview source on
     * MariaDB and another on SQLite, and the two previews are different
     * pictures rather than different bytes of one.
     *
     * This pins the direction, not the presence: reversing the tiebreaker
     * fails here, removing it altogether does not, because SQLite makes uid
     * the rowid and returns these rows in uid order anyway. Only MariaDB can
     * show the removal, and the suite does not run against it locally.
     */
    #[Test]
    public function findSmallestRenditionsBreaksAWidthTieByUid(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame(
            '/_processed_/a/b/csm_tie_low.jpg',
            $this->subject->findSmallestRenditions([105])[105]['identifier'],
        );
    }

    #[Test]
    public function findSmallestRenditionsOmitsAnOriginalWithoutAUsableRendition(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame([], $this->subject->findSmallestRenditions([999]));
    }

    #[Test]
    public function findSmallestRenditionsReturnsEmptyArrayForAnEmptyList(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        self::assertSame([], $this->subject->findSmallestRenditions([]));
    }

    /**
     * The whole point of the batch: every original of one request is resolved
     * together, and the width ordering that spans them all must not leak one
     * original's rows into another's winner. 102's own narrowest is wider
     * than 101's thumbnail and narrower than 103's only usable rendition, so
     * an implementation that took the first row overall, or the last, would
     * show it here.
     */
    #[Test]
    public function findSmallestRenditionsResolvesEveryOriginalOfABatch(): void
    {
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_processedfile.csv');

        $result = $this->subject->findSmallestRenditions([101, 102, 103, 105, 999]);

        self::assertSame([
            101 => '/_processed_/a/b/csm_provisional_thumb.jpg',
            102 => '/_processed_/a/b/csm_real_bbb.jpg',
            103 => '/_processed_/a/b/csm_untouched_ccc.jpg',
            105 => '/_processed_/a/b/csm_tie_low.jpg',
        ], array_map(static fn (array $row): string => $row['identifier'], $result));
    }
}
