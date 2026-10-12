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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Command;

use KonradMichalik\Typo3FileSync\Command\FetchCommand;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Resource\FetchMode;
use KonradMichalik\Typo3FileSync\Resource\Handler\PlaceholderImageResource;
use KonradMichalik\Typo3FileSync\Tests\Functional\Command\Fixtures\StubRemoteResource;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function sprintf;

/**
 * FetchCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(FetchCommand::class)]
final class FetchCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/fetch.csv');

        $extConf = &$GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY];
        $extConf[Configuration::EXTCONF_RESOURCE_HANDLER]['stub_remote'] = [
            'title' => 'Stub Remote',
            'handler' => StubRemoteResource::class,
        ];
        $extConf[Configuration::EXTCONF_STORAGES][1] = [
            ['identifier' => 'stub_remote', 'configuration' => null],
            ['identifier' => 'placeholder_image', 'configuration' => '#CCCCCC,#969696'],
        ];
        StubRemoteResource::$requested = [];

        // The local driver takes a storage offline whose base path is missing.
        $this->basePath = Environment::getPublicPath().'/fileadmin/';
        GeneralUtility::mkdir_deep($this->basePath.'user_upload');
        file_put_contents($this->basePath.'user_upload/local.jpg', 'local-content');
    }

    protected function tearDown(): void
    {
        GeneralUtility::rmdir($this->basePath, true);
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES][1]);
        parent::tearDown();
    }

    #[Test]
    public function fetchesMissingFilesAndLeavesLocalOnesAlone(): void
    {
        $tester = $this->execute(['--path' => '/user_upload/']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('remote-content', file_get_contents($this->basePath.'user_upload/remote.jpg'));
        self::assertSame('local-content', file_get_contents($this->basePath.'user_upload/local.jpg'));
        self::assertNotContains('/user_upload/local.jpg', StubRemoteResource::$requested);
        self::assertSame('stub_remote', $this->syncIdentifierOf(1));
    }

    #[Test]
    public function placeholderHandlersAreSkippedUnlessNamed(): void
    {
        $tester = $this->execute(['--path' => '/user_upload/']);

        self::assertFileDoesNotExist($this->basePath.'user_upload/unavailable.jpg');
        self::assertStringContainsString('1 fetched, 1 unavailable, 0 failed', $tester->getDisplay());
    }

    #[Test]
    public function aPlaceholderHandlerNamedExplicitlyIsUsed(): void
    {
        $this->execute(['--path' => '/user_upload/unavailable.jpg', '--identifier' => ['placeholder_image']]);

        self::assertFileExists($this->basePath.'user_upload/unavailable.jpg');
        self::assertSame([], StubRemoteResource::$requested);
        self::assertSame('placeholder_image', $this->syncIdentifierOf(2));
    }

    #[Test]
    public function dryRunReportsTheMissingFilesWithoutFetchingThem(): void
    {
        $tester = $this->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], StubRemoteResource::$requested);
        self::assertFileDoesNotExist($this->basePath.'user_upload/remote.jpg');
        // Every file but local.jpg: 100 + 200 + 400 + 500 bytes.
        self::assertStringContainsString('4 missing file(s), 1.17 KB', $tester->getDisplay());
    }

    #[Test]
    public function limitCapsTheNumberOfFilesFetched(): void
    {
        $this->execute(['--limit' => '1']);

        self::assertSame(['/user_upload/remote.jpg'], StubRemoteResource::$requested);
    }

    #[Test]
    public function aFileThatFailsIsReportedWithoutStoppingTheRunAndFailsTheExitCode(): void
    {
        $tester = $this->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertFileExists($this->basePath.'other/remote.jpg');
        self::assertStringContainsString('2 fetched, 1 unavailable, 1 failed', $tester->getDisplay());
    }

    #[Test]
    public function aStorageThatIsNotEnabledIsRejected(): void
    {
        $tester = $this->execute(['--storage' => '42']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], StubRemoteResource::$requested);
    }

    #[Test]
    public function anUnknownIdentifierIsRejected(): void
    {
        $tester = $this->execute(['--identifier' => ['remote_instace']]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], StubRemoteResource::$requested);
    }

    #[Test]
    public function anEnabledStorageWithoutSyncDriverIsRejectedWhenRequested(): void
    {
        $tester = $this->execute(['--storage' => '2']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function aNegativeLimitIsRejected(): void
    {
        $tester = $this->execute(['--limit' => '-5']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], StubRemoteResource::$requested);
    }

    /**
     * The scheduler runs every due task in one process, so a restriction left
     * behind would change how the next task fetches.
     */
    #[Test]
    public function theHandlerRestrictionDoesNotOutliveTheRun(): void
    {
        $this->execute(['--path' => '/user_upload/']);

        self::assertTrue($this->get(FetchMode::class)->allows('placeholder_image', new PlaceholderImageResource(null)));
    }

    /**
     * The storage, its driver and the driver's negative cache live as long
     * as the process, which under the scheduler spans several tasks.
     */
    #[Test]
    public function aLaterRunRetriesAFileAnEarlierRunCouldNotFetch(): void
    {
        $this->execute(['--path' => '/user_upload/unavailable.jpg']);
        $this->execute(['--path' => '/user_upload/unavailable.jpg', '--identifier' => ['placeholder_image']]);

        self::assertFileExists($this->basePath.'user_upload/unavailable.jpg');
    }

    #[Test]
    public function filesBeyondOneBatchAreAllFetched(): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('sys_file');
        for ($i = 0; $i < 120; ++$i) {
            $identifier = sprintf('/bulk/%d/remote.jpg', $i);
            $connection->insert('sys_file', [
                'storage' => 1,
                'identifier' => $identifier,
                'identifier_hash' => sha1($identifier),
                'folder_hash' => sha1(sprintf('/bulk/%d/', $i)),
                'name' => 'remote.jpg',
                'extension' => 'jpg',
                'size' => 1,
            ]);
        }

        $tester = $this->execute(['--path' => '/bulk/', '--pause' => '1']);

        self::assertStringContainsString('120 fetched, 0 unavailable, 0 failed', $tester->getDisplay());
        self::assertFileExists($this->basePath.'bulk/119/remote.jpg');
    }

    #[Test]
    public function aNegativePauseIsRejected(): void
    {
        $tester = $this->execute(['--pause' => '-1']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], StubRemoteResource::$requested);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester($this->get(FetchCommand::class));
        $tester->execute($input);

        return $tester;
    }

    private function syncIdentifierOf(int $fileUid): string
    {
        return (string) $this->get(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select([Configuration::FIELD_IDENTIFIER], 'sys_file', ['uid' => $fileUid])
            ->fetchOne();
    }
}
