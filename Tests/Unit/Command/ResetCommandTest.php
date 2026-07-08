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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Command;

use KonradMichalik\Typo3FileSync\Command\ResetCommand;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Service\StorageService;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\{CompositeExpression, ExpressionBuilder};
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * ResetCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ResetCommand::class)]
final class ResetCommandTest extends TestCase
{
    #[Test]
    public function executeResetsMissingFilesForAllEnabledStorages(): void
    {
        $storageService = $this->createStorageService([
            ['uid' => 1, 'name' => 'Storage One'],
            ['uid' => 2, 'name' => 'Storage Two'],
        ]);
        $fileRepository = $this->createFileRepository(3);

        $tester = new CommandTester(new ResetCommand($storageService, $fileRepository));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Reset 3 file(s) in storage "Storage One" (uid: 1)', $tester->getDisplay());
        self::assertStringContainsString('Reset 3 file(s) in storage "Storage Two" (uid: 2)', $tester->getDisplay());
    }

    #[Test]
    public function executeSilentlyIgnoresStoragesWithNoResetCount(): void
    {
        $storageService = $this->createStorageService([
            ['uid' => 1, 'name' => 'Storage One'],
        ]);
        $fileRepository = $this->createFileRepository(0);

        $tester = new CommandTester(new ResetCommand($storageService, $fileRepository));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame('', trim($tester->getDisplay()));
    }

    #[Test]
    public function executeRestrictsToSingleStorageWhenOptionGiven(): void
    {
        $storageService = $this->createStorageService([
            ['uid' => 1, 'name' => 'Storage One'],
            ['uid' => 2, 'name' => 'Storage Two'],
        ]);
        $fileRepository = $this->createFileRepository(1);

        $tester = new CommandTester(new ResetCommand($storageService, $fileRepository));
        $tester->execute(['--storage' => '2']);

        self::assertStringContainsString('Storage Two', $tester->getDisplay());
        self::assertStringNotContainsString('Storage One', $tester->getDisplay());
    }

    /**
     * @param list<array{uid: int, name: string}> $rows
     */
    private function createStorageService(array $rows): StorageService
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('or')->willReturn(CompositeExpression::or('1=1', '1=1'));
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('in')->willReturn('1=1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $storageService = (new ReflectionClass(StorageService::class))->newInstanceWithoutConstructor();
        $property = (new ReflectionClass(StorageService::class))->getProperty('connectionPool');
        $property->setValue($storageService, $connectionPool);

        return $storageService;
    }

    private function createFileRepository(int $resetMissingReturn): FileRepository
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($this->createMock(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn($resetMissingReturn);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();
        $property = (new ReflectionClass(FileRepository::class))->getProperty('connectionPool');
        $property->setValue($fileRepository, $connectionPool);

        return $fileRepository;
    }
}
