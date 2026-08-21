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

use InvalidArgumentException;
use KonradMichalik\Typo3FileSync\Command\DeleteCommand;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Service\StorageService;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\{CompositeExpression, ExpressionBuilder};
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory};

/**
 * DeleteCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(DeleteCommand::class)]
final class DeleteCommandTest extends TestCase
{
    #[Test]
    public function executeThrowsWithoutIdentifierOrAllOption(): void
    {
        $tester = new CommandTester($this->createCommand([], 0));

        $this->expectException(RuntimeException::class);
        $tester->execute([]);
    }

    #[Test]
    public function executeDeletesGivenIdentifiersFromAllEnabledStorages(): void
    {
        $tester = new CommandTester($this->createCommand(
            [['uid' => 1, 'name' => 'Storage One']],
            2,
        ));

        $exitCode = $tester->execute(['--identifier' => ['remote_instance']]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Deleted 2 file(s) from "remote_instance" resource in storage "Storage One" (uid: 1)', $tester->getDisplay());
    }

    #[Test]
    public function executeSilentlySkipsWhenNothingWasDeleted(): void
    {
        $tester = new CommandTester($this->createCommand(
            [['uid' => 1, 'name' => 'Storage One']],
            0,
        ));

        $tester->execute(['--identifier' => ['remote_instance']]);

        self::assertSame('', trim($tester->getDisplay()));
    }

    #[Test]
    public function executeDeletesAllIdentifiersFoundInDatabaseWhenAllOptionIsSet(): void
    {
        $tester = new CommandTester($this->createCommand(
            [['uid' => 1, 'name' => 'Storage One']],
            1,
            [['count' => 1, 'tx_typo3_file_sync_identifier' => 'remote_instance']],
        ));

        $exitCode = $tester->execute(['--all' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Deleted 1 file(s) from "remote_instance" resource in storage "Storage One" (uid: 1)', $tester->getDisplay());
    }

    #[Test]
    public function executeRestrictsToSingleStorageWhenStorageOptionIsSet(): void
    {
        $tester = new CommandTester($this->createCommand(
            [['uid' => 1, 'name' => 'Storage One']],
            3,
        ));

        $exitCode = $tester->execute(['--identifier' => ['remote_instance'], '--storage' => '1']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Deleted 3 file(s) from "remote_instance" resource in storage "Storage One" (uid: 1)', $tester->getDisplay());
    }

    #[Test]
    public function executeFallsBackToUnnamedStorageWhenStorageOptionIsNotEnabled(): void
    {
        $tester = new CommandTester($this->createCommand([], 2));

        $exitCode = $tester->execute(['--identifier' => ['remote_instance'], '--storage' => '99']);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Deleted 2 file(s) from "remote_instance" resource in storage "" (uid: 99)', $tester->getDisplay());
    }

    /**
     * @param list<array{uid: int, name: string}>                            $enabledStorageRows
     * @param list<array{count: int, tx_typo3_file_sync_identifier: string}> $countByIdentifierRows
     */
    private function createCommand(array $enabledStorageRows, int $deleteByIdentifierReturn, array $countByIdentifierRows = []): DeleteCommand
    {
        $storageService = $this->createStorageService($enabledStorageRows);
        $fileRepository = $this->createFileRepository($deleteByIdentifierReturn, $countByIdentifierRows);

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $languageServiceFactory = $this->createMock(LanguageServiceFactory::class);
        $languageServiceFactory->method('create')->willReturn($languageService);

        return new DeleteCommand($storageService, $fileRepository, $languageServiceFactory);
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

    /**
     * @param list<array{count: int, tx_typo3_file_sync_identifier: string}> $countByIdentifierRows
     */
    private function createFileRepository(int $deleteByIdentifierReturn, array $countByIdentifierRows = []): FileRepository
    {
        // deleteByIdentifier() first calls findByIdentifier() (a SELECT), then
        // loops calling storageRepository->getStorageObject() per row — we make
        // that throw InvalidArgumentException so the loop body short-circuits via
        // the existing `catch (InvalidArgumentException) { continue; }`, while
        // still returning count($rows) from findByIdentifier() unaffected.
        $rows = array_fill(0, $deleteByIdentifierReturn, ['storage' => 1, 'identifier' => '/some/file.jpg']);

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        if ([] !== $countByIdentifierRows) {
            // --all first calls countByIdentifier(), then deleteByIdentifier()
            // calls findByIdentifier() once per identifier — both go through
            // this same mocked query builder, so the two fetches are served
            // in that call order.
            $result->method('fetchAllAssociative')->willReturnOnConsecutiveCalls($countByIdentifierRows, $rows);
        } else {
            $result->method('fetchAllAssociative')->willReturn($rows);
        }

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $concreteQueryBuilder = $this->createMock(\Doctrine\DBAL\Query\QueryBuilder::class);
        $concreteQueryBuilder->method('select')->willReturnSelf();

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('groupBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->method('getConcreteQueryBuilder')->willReturn($concreteQueryBuilder);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $storageRepository = $this->createMock(\TYPO3\CMS\Core\Resource\StorageRepository::class);
        $storageRepository->method('getStorageObject')->willThrowException(
            new InvalidArgumentException('no such storage', 1234),
        );

        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(FileRepository::class);
        $reflection->getProperty('connectionPool')->setValue($fileRepository, $connectionPool);
        $reflection->getProperty('storageRepository')->setValue($fileRepository, $storageRepository);

        return $fileRepository;
    }
}
