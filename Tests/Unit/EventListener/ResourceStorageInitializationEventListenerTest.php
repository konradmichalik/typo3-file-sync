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
}
