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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Service;

use KonradMichalik\Typo3FileSync\Service\StorageService;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * StorageServiceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(StorageService::class)]
final class StorageServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_storage.csv');
    }

    #[Test]
    public function getEnabledStoragesReturnsStorageEnabledByFlag(): void
    {
        $result = $this->get(StorageService::class)->getEnabledStorages();

        self::assertArrayHasKey(1, $result);
        self::assertSame('Enabled By Flag', $result[1]['name']);
        self::assertArrayNotHasKey(2, $result);
    }

    #[Test]
    public function getEnabledStoragesIncludesStorageConfiguredViaExtconfEvenWithoutFlag(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'] = [3 => []];

        $result = $this->get(StorageService::class)->getEnabledStorages();

        self::assertArrayHasKey(3, $result);
        self::assertSame('Enabled By Config Only', $result[3]['name']);
    }

    #[Test]
    public function getEnabledStoragesReturnsEmptyArrayWhenNoneEnabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'] = [];
        $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_storage')->truncate('sys_file_storage');
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file_storage_all_disabled.csv');

        $result = $this->get(StorageService::class)->getEnabledStorages();

        self::assertSame([], $result);
    }
}
