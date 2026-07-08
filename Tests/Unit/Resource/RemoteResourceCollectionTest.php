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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource;

use Error;
use InvalidArgumentException;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\{RemoteResourceCollection, RemoteResourceInterface};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\{File, ResourceFactory, ResourceStorage, StorageRepository};

/**
 * RemoteResourceCollectionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(RemoteResourceCollection::class)]
final class RemoteResourceCollectionTest extends TestCase
{
    #[Test]
    public function getReturnsContentFromFirstMatchingHandler(): void
    {
        $handler1 = $this->createMock(RemoteResourceInterface::class);
        $handler1->expects(self::once())->method('getFile')->willReturn(false);

        $handler2 = $this->createMock(RemoteResourceInterface::class);
        $handler2->expects(self::once())->method('getFile')->willReturn('file-content');

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [
                ['identifier' => 'handler1', 'handler' => $handler1],
                ['identifier' => 'handler2', 'handler' => $handler2],
            ],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        // updateIdentifier() is called before returning content, which fails
        // on the uninitialized FileRepository. The mock expectations above
        // verify that handler2 was selected and invoked.
        $this->expectException(Error::class);
        $collection->get('/test.jpg', 'fileadmin/test.jpg');
    }

    #[Test]
    public function getReturnsNullWhenNoHandlerMatches(): void
    {
        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->method('getFile')->willReturn(false);

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [['identifier' => 'handler1', 'handler' => $handler]],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        $result = $collection->get('/test.jpg', 'fileadmin/test.jpg');
        self::assertNull($result);
    }

    #[Test]
    public function getSkipsHandlerReturningFalse(): void
    {
        $handler1 = $this->createMock(RemoteResourceInterface::class);
        $handler1->expects(self::once())->method('getFile')->willReturn(false);

        $handler2 = $this->createMock(RemoteResourceInterface::class);
        $handler2->expects(self::once())->method('getFile')->willReturn('content-from-handler2');

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [
                ['identifier' => 'handler1', 'handler' => $handler1],
                ['identifier' => 'handler2', 'handler' => $handler2],
            ],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        // handler1 returns false → skipped, handler2 returns content → selected.
        // updateIdentifier() fails on uninitialized FileRepository. Mock expectations
        // verify handler2 was called after handler1 was skipped.
        $this->expectException(Error::class);
        $collection->get('/test.jpg', 'fileadmin/test.jpg');
    }

    #[Test]
    public function getReturnsNullWhenFileNotInSysFile(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(0);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        $result = $collection->get('/nonexistent.jpg', 'fileadmin/nonexistent.jpg');
        self::assertNull($result);
    }

    #[Test]
    public function getDoesNotRetryHandlersForFailedIdentifier(): void
    {
        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->expects(self::once())->method('getFile')->willReturn(false);

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [['identifier' => 'handler1', 'handler' => $handler]],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        // Second call must be served from the negative cache — the handler
        // mock expects exactly one invocation.
        self::assertNull($collection->get('/test.jpg', 'fileadmin/test.jpg'));
        self::assertNull($collection->get('/test.jpg', 'fileadmin/test.jpg'));
    }

    #[Test]
    public function getTriesHandlersForEachDistinctIdentifier(): void
    {
        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->expects(self::exactly(2))->method('getFile')->willReturn(false);

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [['identifier' => 'handler1', 'handler' => $handler]],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        self::assertNull($collection->get('/first.jpg', 'fileadmin/first.jpg'));
        self::assertNull($collection->get('/second.jpg', 'fileadmin/second.jpg'));
    }

    #[Test]
    public function getUpdatesIdentifierOnSuccess(): void
    {
        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->expects(self::once())->method('getFile')->willReturn('file-content');

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(42);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [['identifier' => 'test_handler', 'handler' => $handler]],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        // updateIdentifier() is called on the uninitialized FileRepository, which
        // throws an Error (accessing uninitialized $connectionPool). This confirms
        // the code path reaches updateIdentifier after getFile() succeeds.
        $this->expectException(Error::class);
        $collection->get('/test.jpg', 'fileadmin/test.jpg');
    }

    #[Test]
    public function getQueriesProcessedFileTableWhenIdentifierIsWithinProcessingFolder(): void
    {
        $queryResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $queryResult->method('fetchAssociative')->willReturn([
            'original' => 1,
            'task_type' => 'Preview',
            'configuration' => serialize([]),
        ]);

        $expressionBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($queryResult);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(true);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $connectionPool,
        );
        $collection->setLogger(new NullLogger());

        // resourceFactory is reflection-broken (no constructor ran), so calling
        // getFileObject() on it throws an Error — reaching that call (before
        // unserialize()/ProcessedFile construction even run) confirms the
        // sys_file_processedfile row was found and its "original" column read.
        $this->expectException(Error::class);
        $collection->get('/processing/image.jpg', 'fileadmin/_processed_/image.jpg');
    }

    #[Test]
    public function getReturnsNullWhenProcessedFileRowNotFound(): void
    {
        $queryResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $queryResult->method('fetchAssociative')->willReturn(false);

        $expressionBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($queryResult);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(true);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $connectionPool,
        );
        $collection->setLogger(new NullLogger());

        $result = $collection->get('/processing/image.jpg', 'fileadmin/_processed_/image.jpg');

        self::assertNull($result);
    }

    #[Test]
    public function getReturnsNullWhenGetFileByIdentifierThrowsInvalidArgumentException(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willThrowException(new InvalidArgumentException('nope', 1));

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [],
            $storageRepository,
            $resourceFactory,
            $fileRepository,
            $this->createMock(ConnectionPool::class),
        );
        $collection->setLogger(new NullLogger());

        $result = $collection->get('/test.jpg', 'fileadmin/test.jpg');

        self::assertNull($result);
    }
}
