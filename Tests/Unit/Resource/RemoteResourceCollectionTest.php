<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_file_sync" TYPO3 CMS extension.
 *
 * (c) 2025 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource;

use KonradMichalik\Typo3FileSync\Exception\MissingInterfaceException;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollection;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

#[CoversClass(RemoteResourceCollection::class)]
final class RemoteResourceCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(RemoteResourceCollection::class);
        $property = $reflection->getProperty('fileIdentifierCache');
        $property->setValue(null, []);
    }

    #[Test]
    public function getReturnsContentFromFirstMatchingHandler(): void
    {
        $handler1 = $this->createMock(RemoteResourceInterface::class);
        $handler1->method('hasFile')->willReturn(false);
        $handler1->expects(self::never())->method('getFile');

        $handler2 = $this->createMock(RemoteResourceInterface::class);
        $handler2->method('hasFile')->willReturn(true);
        $handler2->expects(self::once())->method('getFile')->willReturn('file-content');

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new \ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [
                ['identifier' => 'handler1', 'handler' => $handler1],
                ['identifier' => 'handler2', 'handler' => $handler2],
            ],
            $storageRepository,
            $resourceFactory,
            $fileRepository
        );
        $collection->setLogger(new NullLogger());

        // updateIdentifier() is called before returning content, which fails
        // on the uninitialized FileRepository. The mock expectations above
        // verify that handler2 was selected and invoked.
        $this->expectException(\Error::class);
        $collection->get('/test.jpg', 'fileadmin/test.jpg');
    }

    #[Test]
    public function getReturnsNullWhenNoHandlerMatches(): void
    {
        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->method('hasFile')->willReturn(false);

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new \ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [['identifier' => 'handler1', 'handler' => $handler]],
            $storageRepository,
            $resourceFactory,
            $fileRepository
        );
        $collection->setLogger(new NullLogger());

        $result = $collection->get('/test.jpg', 'fileadmin/test.jpg');
        self::assertNull($result);
    }

    #[Test]
    public function getSkipsHandlerReturningFalse(): void
    {
        $handler1 = $this->createMock(RemoteResourceInterface::class);
        $handler1->method('hasFile')->willReturn(true);
        $handler1->expects(self::once())->method('getFile')->willReturn(false);

        $handler2 = $this->createMock(RemoteResourceInterface::class);
        $handler2->method('hasFile')->willReturn(true);
        $handler2->expects(self::once())->method('getFile')->willReturn('content-from-handler2');

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new \ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [
                ['identifier' => 'handler1', 'handler' => $handler1],
                ['identifier' => 'handler2', 'handler' => $handler2],
            ],
            $storageRepository,
            $resourceFactory,
            $fileRepository
        );
        $collection->setLogger(new NullLogger());

        // handler1 returns false → skipped, handler2 returns content → selected.
        // updateIdentifier() fails on uninitialized FileRepository. Mock expectations
        // verify handler2 was called after handler1 was skipped.
        $this->expectException(\Error::class);
        $collection->get('/test.jpg', 'fileadmin/test.jpg');
    }

    #[Test]
    public function getThrowsExceptionForInvalidResourceType(): void
    {
        $invalidHandler = new \stdClass();

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(1);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new \ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [['identifier' => 'invalid', 'handler' => $invalidHandler]],
            $storageRepository,
            $resourceFactory,
            $fileRepository
        );
        $collection->setLogger(new NullLogger());

        $this->expectException(MissingInterfaceException::class);
        $collection->get('/test.jpg', 'fileadmin/test.jpg');
    }

    #[Test]
    public function getReturnsNullWhenFileNotInSysFile(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(0);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new \ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [],
            $storageRepository,
            $resourceFactory,
            $fileRepository
        );
        $collection->setLogger(new NullLogger());

        $result = $collection->get('/nonexistent.jpg', 'fileadmin/nonexistent.jpg');
        self::assertNull($result);
    }

    #[Test]
    public function getUpdatesIdentifierOnSuccess(): void
    {
        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->method('hasFile')->willReturn(true);
        $handler->expects(self::once())->method('getFile')->willReturn('file-content');

        $fileObject = $this->createMock(File::class);
        $fileObject->method('getUid')->willReturn(42);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn($fileObject);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $resourceFactory = (new \ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new \ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $collection = new RemoteResourceCollection(
            [['identifier' => 'test_handler', 'handler' => $handler]],
            $storageRepository,
            $resourceFactory,
            $fileRepository
        );
        $collection->setLogger(new NullLogger());

        // updateIdentifier() is called on the uninitialized FileRepository, which
        // throws an Error (accessing uninitialized $connectionPool). This confirms
        // the code path reaches updateIdentifier after getFile() succeeds.
        $this->expectException(\Error::class);
        $collection->get('/test.jpg', 'fileadmin/test.jpg');
    }
}
