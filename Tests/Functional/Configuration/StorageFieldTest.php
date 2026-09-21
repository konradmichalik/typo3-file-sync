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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Configuration;

use KonradMichalik\Typo3FileSync\Configuration;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * StorageFieldTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(Configuration::class)]
final class StorageFieldTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    #[Test]
    public function deferredColumnExistsOnSysFileStorage(): void
    {
        $columns = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file_storage')
            ->createSchemaManager()
            ->listTableColumns('sys_file_storage');

        self::assertArrayHasKey(Configuration::FIELD_DEFERRED, $columns);
    }

    #[Test]
    public function featureToggleDefaultsToOff(): void
    {
        self::assertFalse($GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING]);
    }
}
