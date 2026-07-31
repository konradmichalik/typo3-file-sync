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

use KonradMichalik\Ttt\Attribute\WithTypo3ConfVars;
use KonradMichalik\Typo3FileSync\Exception\UnknownResourceException;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\{RemoteResourceCollectionFactory, RemoteResourceInterface};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\{ResourceFactory, StorageRepository};
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * RemoteResourceCollectionFactoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(RemoteResourceCollectionFactory::class)]
#[WithTypo3ConfVars([
    'EXTCONF' => [
        'typo3_file_sync' => [
            'resourceHandler' => [
                'test_handler' => [
                    'title' => 'Test Handler',
                    'config' => ['label' => 'Test', 'config' => ['type' => 'input']],
                    'handler' => TestRemoteResource::class,
                ],
            ],
        ],
    ],
])]
final class RemoteResourceCollectionFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        // GeneralUtility::xml2array() relies on a registered "runtime" cache;
        // provide a transient in-memory cache manager for FlexForm parsing tests.
        // Configuration (not manual `new Backend(...)`) is used deliberately so
        // CacheManager builds the backend itself with whatever constructor
        // signature the installed typo3/cms-core version actually has.
        $cacheManager = new \TYPO3\CMS\Core\Cache\CacheManager();
        $cacheManager->setCacheConfigurations([
            'runtime' => [
                'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
                'backend' => \TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend::class,
            ],
        ]);
        GeneralUtility::setSingletonInstance(\TYPO3\CMS\Core\Cache\CacheManager::class, $cacheManager);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
    }

    #[Test]
    public function createFromConfigurationCreatesCollection(): void
    {
        $factory = $this->createFactory();
        $collection = $factory->createFromConfiguration([
            ['identifier' => 'test_handler', 'configuration' => null],
        ]);

        self::assertInstanceOf(\KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollection::class, $collection);
    }

    #[Test]
    public function createFromConfigurationSkipsEmptyIdentifier(): void
    {
        $factory = $this->createFactory();
        $collection = $factory->createFromConfiguration([
            ['identifier' => '', 'configuration' => null],
        ]);

        self::assertInstanceOf(\KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollection::class, $collection);
    }

    #[Test]
    public function createFromConfigurationThrowsOnUnknownResource(): void
    {
        $factory = $this->createFactory();

        $this->expectException(UnknownResourceException::class);
        $factory->createFromConfiguration([
            ['identifier' => 'nonexistent_handler'],
        ]);
    }

    #[Test]
    public function createFromConfigurationThrowsWhenHandlerDoesNotImplementInterface(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler']['broken_handler'] = [
            'title' => 'Broken Handler',
            'config' => ['label' => 'Test', 'config' => ['type' => 'input']],
            'handler' => stdClass::class,
        ];

        $factory = $this->createFactory();

        $this->expectException(\KonradMichalik\Typo3FileSync\Exception\MissingInterfaceException::class);
        $factory->createFromConfiguration([
            ['identifier' => 'broken_handler', 'configuration' => null],
        ]);
    }

    #[Test]
    public function createFromConfigurationSetsLoggerOnLoggerAwareHandler(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler']['logger_aware_handler'] = [
            'title' => 'Logger Aware Handler',
            'config' => ['label' => 'Test', 'config' => ['type' => 'input']],
            'handler' => TestLoggerAwareRemoteResource::class,
        ];

        $storageRepository = $this->createMock(StorageRepository::class);
        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();
        $connectionPool = $this->createMock(ConnectionPool::class);

        $logManager = $this->createMock(LogManager::class);
        $logManager->expects(self::once())->method('getLogger')->willReturn(new \Psr\Log\NullLogger());

        $factory = new RemoteResourceCollectionFactory($storageRepository, $resourceFactory, $fileRepository, $connectionPool, $logManager);
        $factory->createFromConfiguration([
            ['identifier' => 'logger_aware_handler', 'configuration' => null],
        ]);
    }

    #[Test]
    public function createFromFlexFormBuildsCollectionFromParsedXml(): void
    {
        $flexForm = <<<'XML'
<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="resources">
                    <el>
                        <numIndex index="0">
                            <test_handler>
                                <el>
                                    <test_handler>
                                        <vDEF>test-config</vDEF>
                                    </test_handler>
                                </el>
                            </test_handler>
                        </numIndex>
                    </el>
                </field>
            </language>
        </sheet>
    </data>
</T3FlexForms>
XML;

        $factory = $this->createFactory();
        $collection = $factory->createFromFlexForm($flexForm);

        self::assertInstanceOf(\KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollection::class, $collection);
    }

    #[Test]
    public function createFromFlexFormThrowsOnUnknownIdentifier(): void
    {
        $flexForm = <<<'XML'
<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="resources">
                    <el>
                        <numIndex index="0">
                            <nonexistent_handler>
                                <el>
                                    <nonexistent_handler>
                                        <vDEF></vDEF>
                                    </nonexistent_handler>
                                </el>
                            </nonexistent_handler>
                        </numIndex>
                    </el>
                </field>
            </language>
        </sheet>
    </data>
</T3FlexForms>
XML;

        $factory = $this->createFactory();

        $this->expectException(UnknownResourceException::class);
        $factory->createFromFlexForm($flexForm);
    }

    private function createFactory(): RemoteResourceCollectionFactory
    {
        $storageRepository = $this->createMock(StorageRepository::class);
        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $connectionPool = $this->createMock(ConnectionPool::class);
        $logManager = (new ReflectionClass(LogManager::class))->newInstanceWithoutConstructor();

        return new RemoteResourceCollectionFactory($storageRepository, $resourceFactory, $fileRepository, $connectionPool, $logManager);
    }
}

/**
 * TestRemoteResource.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
class TestRemoteResource implements RemoteResourceInterface
{
    public function __construct(
        private readonly mixed $configuration = null, // @phpstan-ignore property.onlyWritten
    ) {}

    public function getFile(string $fileIdentifier, string $filePath, ?\TYPO3\CMS\Core\Resource\FileInterface $fileObject = null): mixed
    {
        return false;
    }
}

/**
 * TestLoggerAwareRemoteResource.
 *
 * @internal
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
class TestLoggerAwareRemoteResource implements RemoteResourceInterface, \Psr\Log\LoggerAwareInterface
{
    use \Psr\Log\LoggerAwareTrait;

    public function getFile(string $fileIdentifier, string $filePath, ?\TYPO3\CMS\Core\Resource\FileInterface $fileObject = null): mixed
    {
        return false;
    }
}
