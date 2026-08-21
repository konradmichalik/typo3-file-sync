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
use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3FileSync\EventListener\ResourceStorageInitializationEventListener;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollectionFactory;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\{ResourceFactory, ResourceStorage, StorageRepository};
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * ResourceStorageInitializationEventListenerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ResourceStorageInitializationEventListener::class)]
final class ResourceStorageInitializationEventListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']);
    }

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

        $existingFileSyncDriver = (new ReflectionClass(FileSyncDriver::class))
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
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'] = [
            1 => [['identifier' => 'test_handler', 'configuration' => null]],
        ];

        // The factory is never invoked on this code path: getOriginalDriver()
        // throws before createFromConfiguration() would be called. Only the
        // storages GLOBALS entry above is needed to make isStorageConfigured
        // evaluate to true, so the factory is built without wiring up any of
        // its real dependencies.
        $factory = (new ReflectionClass(RemoteResourceCollectionFactory::class))->newInstanceWithoutConstructor();

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
        // Closure::bind accessor triggers an Error reading the typed property
        // (message/exact subclass varies by typo3/cms-core version: "must not
        // be accessed before initialization" on some versions, a TypeError
        // "Return value must be of type DriverInterface, null returned" on
        // others — both are \Error subtypes). Reaching either confirms the
        // configured-storage branch was taken past the early-return guard,
        // i.e. driver construction was reached.
        $this->expectException(Error::class);
        $listener($event);
    }

    #[Test]
    #[WithEnvironment(projectPath: 'self')]
    public function listenerBuildsAndAssignsFileSyncDriverWhenRecordIsEnabled(): void
    {
        // GeneralUtility::xml2array() relies on a registered "runtime" cache.
        $cacheManager = new CacheManager();
        $cacheManager->setCacheConfigurations([
            'runtime' => [
                'frontend' => VariableFrontend::class,
                'backend' => TransientMemoryBackend::class,
            ],
        ]);
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);

        // LocalDriver only allows base paths within Environment::getProjectPath()
        // or Environment::getPublicPath() (see GeneralUtility::isAllowedAbsPath()).
        $basePath = Environment::getProjectPath().'/var/tests/file-sync-listener-test/';
        if (!is_dir($basePath)) {
            mkdir($basePath, 0777, true);
        }

        try {
            $factory = $this->createFactory();

            $originalDriver = new LocalDriver(['basePath' => $basePath]);
            $originalDriver->processConfiguration();
            $originalDriver->initialize();

            $storage = $this->createMock(ResourceStorage::class);
            $storage->method('getStorageRecord')->willReturn([
                'uid' => 1,
                'driver' => 'Local',
                'tx_typo3_file_sync_enable' => 1,
                'tx_typo3_file_sync_resources' => '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="resources"><el></el></field></language></sheet></data></T3FlexForms>',
            ]);
            $storage->method('getUid')->willReturn(1);
            $storage->method('getName')->willReturn('Test');
            $storage->method('getConfiguration')->willReturn(['basePath' => $basePath]);
            $storage->method('getCapabilities')->willReturn(new Capabilities());
            $storage->expects(self::once())->method('setDriver')->with(self::isInstanceOf(FileSyncDriver::class));

            (new ReflectionClass(ResourceStorage::class))->getProperty('driver')->setValue($storage, $originalDriver);

            $event = new AfterResourceStorageInitializationEvent($storage);

            $listener = new ResourceStorageInitializationEventListener($factory);
            $listener->setLogger(new NullLogger());
            $listener($event);
        } finally {
            GeneralUtility::purgeInstances();
            rmdir($basePath);
        }
    }

    #[Test]
    #[WithEnvironment(projectPath: 'self')]
    public function listenerBuildsDriverFromConfigurationAndSwallowsInvalidBasePath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'] = [
            // A non-empty array marks the storage as configured; the empty
            // identifier inside is skipped by createFromConfiguration(), so no
            // resource handler needs to be registered for this test.
            1 => [['identifier' => '']],
        ];

        $factory = $this->createFactory();

        // A basePath that does not exist makes FileSyncDriver::processConfiguration()
        // (inherited from LocalDriver) throw InvalidConfigurationException, which
        // the listener deliberately swallows before still calling initialize()
        // and setDriver() — LocalDriver::initialize() is a no-op, so the flow
        // completes regardless of the earlier configuration failure.
        $nonExistentBasePath = Environment::getProjectPath().'/var/tests/file-sync-listener-test-missing/';

        $originalDriver = new LocalDriver(['basePath' => Environment::getProjectPath().'/var/']);
        $originalDriver->processConfiguration();
        $originalDriver->initialize();

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getStorageRecord')->willReturn([
            'uid' => 1,
            'driver' => 'Local',
            'tx_typo3_file_sync_enable' => 0,
            'tx_typo3_file_sync_resources' => '',
        ]);
        $storage->method('getUid')->willReturn(1);
        $storage->method('getName')->willReturn('Test');
        $storage->method('getConfiguration')->willReturn(['basePath' => $nonExistentBasePath]);
        $storage->method('getCapabilities')->willReturn(new Capabilities());
        $storage->expects(self::once())->method('setDriver')->with(self::isInstanceOf(FileSyncDriver::class));

        (new ReflectionClass(ResourceStorage::class))->getProperty('driver')->setValue($storage, $originalDriver);

        $event = new AfterResourceStorageInitializationEvent($storage);

        $listener = new ResourceStorageInitializationEventListener($factory);
        $listener->setLogger(new NullLogger());

        try {
            $listener($event);
        } finally {
            GeneralUtility::purgeInstances();
        }
    }

    private function createFactory(): RemoteResourceCollectionFactory
    {
        return new RemoteResourceCollectionFactory(
            $this->createMock(StorageRepository::class),
            (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor(),
            $this->createMock(ConnectionPool::class),
            (new ReflectionClass(LogManager::class))->newInstanceWithoutConstructor(),
        );
    }
}
