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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Repository;

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\{File, ProcessedFile, ProcessedFileRepository, StorageRepository};

/**
 * FileRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(FileRepository::class)]
final class FileRepositoryTest extends TestCase
{
    #[Test]
    public function updateIdentifierExecutesUpdateStatement(): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->expects(self::once())->method('executeStatement')->willReturn(1);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $subject = new FileRepository(
            $connectionPool,
            $this->createMock(ProcessedFileRepository::class),
            $this->createMock(StorageRepository::class),
        );

        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn(5);

        $subject->updateIdentifier($file, 'remote_instance');
    }

    #[Test]
    public function deleteByIdentifierDeletesExistingProcessedFilesAndLocalFileThenClearsIdentifier(): void
    {
        $localFile = tempnam(sys_get_temp_dir(), 'file-sync-test-');
        self::assertIsString($localFile);

        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn(7);
        $file->method('getForLocalProcessing')->with(false)->willReturn($localFile);

        $existingProcessedFile = $this->createMock(ProcessedFile::class);
        $existingProcessedFile->method('exists')->willReturn(true);
        $existingProcessedFile->expects(self::once())->method('delete')->with(true);

        $missingProcessedFile = $this->createMock(ProcessedFile::class);
        $missingProcessedFile->method('exists')->willReturn(false);
        $missingProcessedFile->expects(self::never())->method('delete');

        $storage = $this->createMock(\TYPO3\CMS\Core\Resource\ResourceStorage::class);
        $storage->method('getFileByIdentifier')->with('/foo/bar.jpg')->willReturn($file);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->with(1)->willReturn($storage);

        $processedFileRepository = $this->createMock(ProcessedFileRepository::class);
        $processedFileRepository->method('findAllByOriginalFile')->with($file)->willReturn([
            $existingProcessedFile,
            $missingProcessedFile,
        ]);

        $selectResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $selectResult->method('fetchAllAssociative')->willReturn([
            ['storage' => 1, 'identifier' => '/foo/bar.jpg'],
        ]);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('groupBy')->willReturnSelf();
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($selectResult);
        $queryBuilder->expects(self::once())->method('executeStatement')->willReturn(1);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $subject = new FileRepository($connectionPool, $processedFileRepository, $storageRepository);

        $count = $subject->deleteByIdentifier('/synced/bar.jpg');

        self::assertSame(1, $count);
        self::assertFileDoesNotExist($localFile);
    }
}
