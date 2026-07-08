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

use Error;
use KonradMichalik\Typo3FileSync\EventListener\ResourceStorageInitializationEventListener;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollectionFactory;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * ResourceStorageInitializationEventListenerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ResourceStorageInitializationEventListener::class)]
final class ResourceStorageInitializationEventListenerTest extends TestCase
{
    #[Test]
    public function listenerSkipsNonLocalDriver(): void
    {
        $this->expectNotToPerformAssertions();

        $factory = (new ReflectionClass(RemoteResourceCollectionFactory::class))->newInstanceWithoutConstructor();

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getStorageRecord')->willReturn([
            'driver' => 'S3',
            'tx_typo3_file_sync_enable' => 1,
            'tx_typo3_file_sync_resources' => '<xml />',
        ]);
        $storage->method('getUid')->willReturn(1);
        $storage->method('getName')->willReturn('Test');

        $event = new AfterResourceStorageInitializationEvent($storage);

        $listener = new ResourceStorageInitializationEventListener($factory);
        $listener->setLogger(new NullLogger());
        $listener($event);
    }

    #[Test]
    public function listenerSkipsWhenNotEnabled(): void
    {
        $this->expectNotToPerformAssertions();

        $factory = (new ReflectionClass(RemoteResourceCollectionFactory::class))->newInstanceWithoutConstructor();

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getStorageRecord')->willReturn([
            'driver' => 'Local',
            'tx_typo3_file_sync_enable' => 0,
            'tx_typo3_file_sync_resources' => '',
        ]);
        $storage->method('getUid')->willReturn(1);
        $storage->method('getName')->willReturn('Test');

        $event = new AfterResourceStorageInitializationEvent($storage);

        $listener = new ResourceStorageInitializationEventListener($factory);
        $listener->setLogger(new NullLogger());
        $listener($event);
    }

    #[Test]
    public function listenerReturnsEarlyWhenOriginalDriverIsAlreadyFileSyncDriver(): void
    {
        $factory = (new ReflectionClass(RemoteResourceCollectionFactory::class))->newInstanceWithoutConstructor();

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getStorageRecord')->willReturn([
            'driver' => 'Local',
            'tx_typo3_file_sync_enable' => 1,
            'tx_typo3_file_sync_resources' => '<xml />',
        ]);
        $storage->method('getUid')->willReturn(1);
        $storage->method('getName')->willReturn('Test');
        $storage->expects(self::never())->method('setDriver');

        $existingFileSyncDriver = (new ReflectionClass(\KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver::class))
            ->newInstanceWithoutConstructor();
        (new ReflectionClass(ResourceStorage::class))->getProperty('driver')->setValue($storage, $existingFileSyncDriver);

        $event = new AfterResourceStorageInitializationEvent($storage);

        $listener = new ResourceStorageInitializationEventListener($factory);
        $listener->setLogger(new NullLogger());
        $listener($event);
    }

    #[Test]
    public function listenerReachesDriverConstructionWhenStorageIsConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = [
            'test_handler' => [
                'title' => 'Test Handler',
                'config' => ['label' => 'Test', 'config' => ['type' => 'input']],
                'handler' => NullRemoteResource::class,
            ],
        ];
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'] = [
            1 => [['identifier' => 'test_handler', 'configuration' => null]],
        ];

        $storageRepository = $this->createMock(\TYPO3\CMS\Core\Resource\StorageRepository::class);
        $resourceFactory = (new ReflectionClass(\TYPO3\CMS\Core\Resource\ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(\KonradMichalik\Typo3FileSync\Repository\FileRepository::class))->newInstanceWithoutConstructor();
        $connectionPool = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $logManager = (new ReflectionClass(\TYPO3\CMS\Core\Log\LogManager::class))->newInstanceWithoutConstructor();

        $factory = new RemoteResourceCollectionFactory($storageRepository, $resourceFactory, $fileRepository, $connectionPool, $logManager);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getStorageRecord')->willReturn([
            'uid' => 1,
            'driver' => 'Local',
            'tx_typo3_file_sync_enable' => 0,
            'tx_typo3_file_sync_resources' => '',
        ]);
        $storage->method('getUid')->willReturn(1);
        $storage->method('getName')->willReturn('Test');
        $storage->method('getConfiguration')->willReturn([]);

        $event = new AfterResourceStorageInitializationEvent($storage);

        $listener = new ResourceStorageInitializationEventListener($factory);
        $listener->setLogger(new NullLogger());

        // ResourceStorage::$driver is a typed, uninitialized property on this
        // bare mock. The guard clause passes (record disabled but storage is
        // configured), so execution reaches getOriginalDriver(), whose
        // Closure::bind accessor triggers PHP's "must not be accessed before
        // initialization" Error when reading the typed property. Reaching
        // that Error confirms the configured-storage branch was taken past
        // the early-return guard, i.e. driver construction was reached.
        $this->expectException(Error::class);
        $this->expectExceptionMessage('must not be accessed before initialization');
        $listener($event);
    }
}

/**
 * NullRemoteResource.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
class NullRemoteResource implements \KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface
{
    public function getFile(string $fileIdentifier, string $filePath, ?\TYPO3\CMS\Core\Resource\FileInterface $fileObject = null): mixed
    {
        return false;
    }
}
