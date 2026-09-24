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

use KonradMichalik\Typo3FileSync\Resource\StorageDriver;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * StorageDriverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(StorageDriver::class)]
final class StorageDriverTest extends TestCase
{
    #[Test]
    public function extractReturnsTheStoragesPrivateDriver(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $storage = new ResourceStorage($driver, ['uid' => 1], $this->createMock(EventDispatcherInterface::class));

        self::assertSame($driver, StorageDriver::extract($storage));
    }
}
