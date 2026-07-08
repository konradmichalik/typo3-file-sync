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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Resource\Driver;

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\{RemoteResourceCollection, RemoteResourceInterface};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\{File, ResourceFactory, ResourceStorage, StorageRepository};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * FileSyncDriverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(FileSyncDriver::class)]
final class FileSyncDriverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/sys_file.csv');
        $this->basePath = Environment::getVarPath().'/tests/file-sync-driver/';
        GeneralUtility::mkdir_deep($this->basePath);
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->basePath, true);
        parent::tearDown();
    }

    #[Test]
    public function fileExistsFetchesMissingFileFromRemoteResourceAndPersistsSyncState(): void
    {
        $driver = $this->createDriver($this->createRemoteResourceCollection('remote-file-content'));

        self::assertTrue($driver->fileExists('/missing.jpg'));
        self::assertFileExists($this->basePath.'missing.jpg');
        self::assertSame('remote-file-content', file_get_contents($this->basePath.'missing.jpg'));

        $row = $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['tx_typo3_file_sync_identifier', 'missing'], 'sys_file', ['uid' => 1])
            ->fetchAssociative();
        self::assertSame('stub-handler', $row['tx_typo3_file_sync_identifier']);
        self::assertSame(0, (int) $row['missing']);
    }

    #[Test]
    public function fileExistsReturnsFalseWhenNoResourceCanDeliverFile(): void
    {
        $driver = $this->createDriver($this->createRemoteResourceCollection(false));

        self::assertFalse($driver->fileExists('/missing.jpg'));
        self::assertFileDoesNotExist($this->basePath.'missing.jpg');
    }

    #[Test]
    public function isCaseSensitiveFileSystemAlwaysReturnsTrue(): void
    {
        $driver = $this->createDriver($this->createRemoteResourceCollection(false));

        self::assertTrue($driver->isCaseSensitiveFileSystem());
    }

    #[Test]
    public function ensureFileExistsSkipsRemoteFetchWhenLocalFileAlreadyExists(): void
    {
        file_put_contents($this->basePath.'already-here.jpg', 'local-content');

        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->expects(self::never())->method('getFile');

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn(
            new File(['uid' => 1, 'identifier' => '/already-here.jpg', 'storage' => 1], $storage),
        );
        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        $remoteResourceCollection = new RemoteResourceCollection(
            [['identifier' => 'stub-handler', 'handler' => $handler]],
            $storageRepository,
            $this->get(ResourceFactory::class),
            $this->get(FileRepository::class),
            $this->get(ConnectionPool::class),
        );

        $driver = $this->createDriver($remoteResourceCollection);

        self::assertTrue($driver->fileExists('/already-here.jpg'));
        self::assertSame('local-content', file_get_contents($this->basePath.'already-here.jpg'));
    }

    #[Test]
    public function getFileContentsReturnsFetchedRemoteContent(): void
    {
        $driver = $this->createDriver($this->createRemoteResourceCollection('remote-file-content'));

        self::assertSame('remote-file-content', $driver->getFileContents('/missing.jpg'));
    }

    #[Test]
    public function folderExistsReturnsTrueWhenOriginalDriverReportsFolderExists(): void
    {
        mkdir($this->basePath.'subfolder');

        $driver = $this->createDriver($this->createRemoteResourceCollection(false));

        self::assertTrue($driver->folderExists('/subfolder/'));
    }

    #[Test]
    public function folderExistsReturnsFalseForNonExistentFolderIdentifier(): void
    {
        $driver = $this->createDriver($this->createRemoteResourceCollection(false));

        self::assertFalse($driver->folderExists('/nonexistent-folder/'));
    }

    private function createDriver(RemoteResourceCollection $remoteResourceCollection): FileSyncDriver
    {
        $configuration = ['basePath' => $this->basePath];

        $originalDriver = new LocalDriver($configuration);
        $originalDriver->processConfiguration();
        $originalDriver->initialize();

        $driver = new FileSyncDriver($configuration, $originalDriver, $remoteResourceCollection);
        $driver->processConfiguration();
        $driver->initialize();

        return $driver;
    }

    private function createRemoteResourceCollection(string|false $handlerResult): RemoteResourceCollection
    {
        $handler = $this->createMock(RemoteResourceInterface::class);
        $handler->method('getFile')->willReturn($handlerResult);

        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getUid')->willReturn(1);
        $storage->method('isWithinProcessingFolder')->willReturn(false);
        $storage->method('getFileByIdentifier')->willReturn(
            new File(['uid' => 1, 'identifier' => '/missing.jpg', 'storage' => 1], $storage),
        );

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->willReturn($storage);

        return new RemoteResourceCollection(
            [['identifier' => 'stub-handler', 'handler' => $handler]],
            $storageRepository,
            $this->get(ResourceFactory::class),
            $this->get(FileRepository::class),
            $this->get(ConnectionPool::class),
        );
    }
}
