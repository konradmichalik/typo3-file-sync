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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource\Preview;

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\{BatchRemoteResourceInterface, FetchMode, RemoteResourceCollection, RemoteResourceInterface};
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\Preview\{PreviewGenerator, PreviewSourceReader};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\{ResourceFactory, ResourceStorage, StorageRepository};

/**
 * PreviewSourceReaderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PreviewSourceReader::class)]
final class PreviewSourceReaderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['defaultFilterCallbacks'] = [];
    }

    #[Test]
    public function readIsNullForALocationWhoseStorageIsNotSyncEnabled(): void
    {
        $storage = $this->storageWithDriver($this->createMock(DriverInterface::class));

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => null], $result);
    }

    #[Test]
    public function readIsNullWhenTheStorageCannotBeFound(): void
    {
        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->willThrowException(new RuntimeException('storage gone'));

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => null], $result);
    }

    #[Test]
    public function readIsNullWhenResolvingTheRemotePathFails(): void
    {
        $originalDriver = $this->createMock(DriverInterface::class);
        $originalDriver->method('getPublicUrl')->willThrowException(new RuntimeException('boom'));

        $fileSyncDriver = new FileSyncDriver([], $originalDriver, $this->remoteResourceCollection([]));
        $storage = $this->storageWithDriver($fileSyncDriver);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => null], $result);
    }

    #[Test]
    public function readSurvivesAPrefetchFailureAndStillAttemptsTheFetch(): void
    {
        $originalDriver = $this->createMock(DriverInterface::class);
        $originalDriver->method('getPublicUrl')->willReturn('https://remote.example.com/a.jpg');

        /** @var BatchRemoteResourceInterface&MockObject&RemoteResourceInterface $batchHandler */
        $batchHandler = $this->createMockForIntersectionOfInterfaces([RemoteResourceInterface::class, BatchRemoteResourceInterface::class]);
        $batchHandler->method('prefetch')->willThrowException(new RuntimeException('prefetch boom'));

        $fileSyncDriver = new FileSyncDriver([], $originalDriver, $this->remoteResourceCollection([
            ['identifier' => 'batch', 'handler' => $batchHandler],
        ]));
        $storage = $this->storageWithDriver($fileSyncDriver);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => null], $result);
    }

    #[Test]
    public function readReturnsNullWhenTheStorageBecomesUnavailableBetweenResolvingAndFetching(): void
    {
        $originalDriver = $this->createMock(DriverInterface::class);
        $originalDriver->method('getPublicUrl')->willReturn('https://remote.example.com/a.jpg');

        $fileSyncDriver = new FileSyncDriver([], $originalDriver, $this->remoteResourceCollection([]));
        $storage = $this->storageWithDriver($fileSyncDriver);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->willReturnOnConsecutiveCalls($storage, $storage, null);

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => null], $result);
    }

    #[Test]
    public function readSkipsAHandlerThatThrowsAndUsesTheNextOne(): void
    {
        $originalDriver = $this->createMock(DriverInterface::class);
        $originalDriver->method('getPublicUrl')->willReturn('https://remote.example.com/a.jpg');

        /** @var BatchRemoteResourceInterface&MockObject&RemoteResourceInterface $failingHandler */
        $failingHandler = $this->createMockForIntersectionOfInterfaces([RemoteResourceInterface::class, BatchRemoteResourceInterface::class]);
        $failingHandler->method('getFile')->willThrowException(new RuntimeException('handler boom'));

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'preview-bytes');
        rewind($stream);
        /** @var BatchRemoteResourceInterface&MockObject&RemoteResourceInterface $workingHandler */
        $workingHandler = $this->createMockForIntersectionOfInterfaces([RemoteResourceInterface::class, BatchRemoteResourceInterface::class]);
        $workingHandler->method('getFile')->willReturn($stream);

        $fileSyncDriver = new FileSyncDriver([], $originalDriver, $this->remoteResourceCollection([
            ['identifier' => 'failing', 'handler' => $failingHandler],
            ['identifier' => 'working', 'handler' => $workingHandler],
        ]));
        $storage = $this->storageWithDriver($fileSyncDriver);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => 'preview-bytes'], $result);
    }

    #[Test]
    public function readIsNullWhenTheHandlerYieldsAnEmptyStream(): void
    {
        $originalDriver = $this->createMock(DriverInterface::class);
        $originalDriver->method('getPublicUrl')->willReturn('https://remote.example.com/a.jpg');

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        /** @var BatchRemoteResourceInterface&MockObject&RemoteResourceInterface $handler */
        $handler = $this->createMockForIntersectionOfInterfaces([RemoteResourceInterface::class, BatchRemoteResourceInterface::class]);
        $handler->method('getFile')->willReturn($stream);

        $fileSyncDriver = new FileSyncDriver([], $originalDriver, $this->remoteResourceCollection([
            ['identifier' => 'empty', 'handler' => $handler],
        ]));
        $storage = $this->storageWithDriver($fileSyncDriver);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => null], $result);
    }

    #[Test]
    public function readIsNullWhenTheHandlerYieldsMoreBytesThanThePreviewCapAllows(): void
    {
        $originalDriver = $this->createMock(DriverInterface::class);
        $originalDriver->method('getPublicUrl')->willReturn('https://remote.example.com/a.jpg');

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat('x', PreviewGenerator::MAX_BYTES + 1));
        rewind($stream);
        /** @var BatchRemoteResourceInterface&MockObject&RemoteResourceInterface $handler */
        $handler = $this->createMockForIntersectionOfInterfaces([RemoteResourceInterface::class, BatchRemoteResourceInterface::class]);
        $handler->method('getFile')->willReturn($stream);

        $fileSyncDriver = new FileSyncDriver([], $originalDriver, $this->remoteResourceCollection([
            ['identifier' => 'oversized', 'handler' => $handler],
        ]));
        $storage = $this->storageWithDriver($fileSyncDriver);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('findByUid')->with(1)->willReturn($storage);

        $subject = new PreviewSourceReader($storageRepository);
        $subject->setLogger(new NullLogger());

        $result = $subject->read(['key' => ['storage' => 1, 'identifier' => '/a.jpg']]);

        self::assertSame(['key' => null], $result);
    }

    private function storageWithDriver(DriverInterface $driver): ResourceStorage
    {
        return new ResourceStorage($driver, ['uid' => 1, 'name' => 'test'], $this->createMock(EventDispatcherInterface::class));
    }

    /**
     * @param array<int, array{identifier: string, handler: RemoteResourceInterface}> $resources
     */
    private function remoteResourceCollection(array $resources): RemoteResourceCollection
    {
        return new RemoteResourceCollection(
            $resources,
            $this->createMock(StorageRepository::class),
            (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor(),
            $this->createMock(ConnectionPool::class),
            1,
            new FetchMode(),
        );
    }
}
