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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Controller;

use Error;
use InvalidArgumentException;
use KonradMichalik\Typo3FileSync\Controller\StorageController;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * StorageControllerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(StorageController::class)]
final class StorageControllerTest extends TestCase
{
    #[Test]
    public function resetMissingReturns403WithoutBackendUser(): void
    {
        $request = $this->createRequest(null, ['storageUid' => 1]);

        $response = $this->createController()->resetMissingAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function resetMissingReturns403ForNonAdminUser(): void
    {
        $request = $this->createRequest($this->createBackendUser(false), ['storageUid' => 1]);

        $response = $this->createController()->resetMissingAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function resetMissingReturns400ForInvalidStorageUid(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), ['storageUid' => 0]);

        $response = $this->createController()->resetMissingAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function resetMissingReturns400ForInvalidBody(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), null);

        $response = $this->createController()->resetMissingAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function resetMissingReachesRepositoryForAdminUser(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), ['storageUid' => 1]);

        // The uninitialized FileRepository throws an Error when accessed —
        // this confirms the request passed authorization and validation.
        $this->expectException(Error::class);
        $this->createController()->resetMissingAction($request);
    }

    #[Test]
    public function resetMissingReturnsSuccessJsonWithAffectedCount(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), ['storageUid' => 1]);

        $response = $this->createController($this->createFileRepositoryWithResetMissingReturn(5))->resetMissingAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['success' => true, 'message' => 'Reset 5 file(s)', 'count' => 5], $body);
    }

    #[Test]
    public function deleteFilesReturns400ForInvalidBody(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), null);

        $response = $this->createController()->deleteFilesAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function deleteFilesReturns400ForInvalidStorageUid(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), ['storageUid' => 0, 'identifier' => 'remote_instance']);

        $response = $this->createController()->deleteFilesAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function deleteFilesReturns403WithoutBackendUser(): void
    {
        $request = $this->createRequest(null, ['storageUid' => 1, 'identifier' => 'remote_instance']);

        $response = $this->createController()->deleteFilesAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function deleteFilesReturns403ForNonAdminUser(): void
    {
        $request = $this->createRequest($this->createBackendUser(false), ['storageUid' => 1, 'identifier' => 'remote_instance']);

        $response = $this->createController()->deleteFilesAction($request);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function deleteFilesReturns400ForMissingIdentifier(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), ['storageUid' => 1, 'identifier' => '']);

        $response = $this->createController()->deleteFilesAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function deleteFilesReachesRepositoryForAdminUser(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), ['storageUid' => 1, 'identifier' => 'remote_instance']);

        // The uninitialized FileRepository throws an Error when accessed —
        // this confirms the request passed authorization and validation.
        $this->expectException(Error::class);
        $this->createController()->deleteFilesAction($request);
    }

    #[Test]
    public function deleteFilesReturnsSuccessJsonWithDeletedCount(): void
    {
        $request = $this->createRequest($this->createBackendUser(true), ['storageUid' => 1, 'identifier' => 'remote_instance']);

        $response = $this->createController($this->createFileRepositoryWithDeleteByIdentifierReturn(3))->deleteFilesAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['success' => true, 'message' => 'Deleted 3 file(s)', 'count' => 3], $body);
    }

    private function createController(?FileRepository $fileRepository = null): StorageController
    {
        $fileRepository ??= (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();

        return new StorageController($fileRepository);
    }

    private function createFileRepositoryWithResetMissingReturn(int $affectedRows): FileRepository
    {
        $queryBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\QueryBuilder::class);
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($this->createMock(\TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeStatement')->willReturn($affectedRows);

        $connectionPool = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(FileRepository::class))->getProperty('connectionPool')->setValue($fileRepository, $connectionPool);

        return $fileRepository;
    }

    private function createFileRepositoryWithDeleteByIdentifierReturn(int $deletedCount): FileRepository
    {
        $rows = array_fill(0, $deletedCount, ['storage' => 1, 'identifier' => '/some/file.jpg']);

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $expressionBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(\TYPO3\CMS\Core\Database\Query\QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('groupBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(\TYPO3\CMS\Core\Database\ConnectionPool::class);
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

    private function createBackendUser(bool $isAdmin): BackendUserAuthentication
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn($isAdmin);

        return $backendUser;
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createRequest(?BackendUserAuthentication $backendUser, ?array $parsedBody): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->with('backend.user')->willReturn($backendUser);
        $request->method('getParsedBody')->willReturn($parsedBody);

        return $request;
    }
}
