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

use KonradMichalik\Typo3FileSync\Exception\UnknownResourceException;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\{RemoteResourceCollectionFactory, RemoteResourceInterface};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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
final class RemoteResourceCollectionFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = [
            'test_handler' => [
                'title' => 'Test Handler',
                'config' => ['label' => 'Test', 'config' => ['type' => 'input']],
                'handler' => TestRemoteResource::class,
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']);
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

    private function createFactory(): RemoteResourceCollectionFactory
    {
        $storageRepository = $this->createMock(StorageRepository::class);
        $resourceFactory = (new ReflectionClass(ResourceFactory::class))->newInstanceWithoutConstructor();
        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        $logManager = (new ReflectionClass(LogManager::class))->newInstanceWithoutConstructor();

        return new RemoteResourceCollectionFactory($storageRepository, $resourceFactory, $fileRepository, $logManager);
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

    public function hasFile(string $fileIdentifier, string $filePath, ?\TYPO3\CMS\Core\Resource\FileInterface $fileObject = null): bool
    {
        return false;
    }

    public function getFile(string $fileIdentifier, string $filePath, ?\TYPO3\CMS\Core\Resource\FileInterface $fileObject = null): mixed
    {
        return false;
    }
}
