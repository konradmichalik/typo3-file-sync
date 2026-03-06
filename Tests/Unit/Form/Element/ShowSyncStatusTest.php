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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Form\Element;

use Doctrine\DBAL\ParameterType;
use KonradMichalik\Typo3FileSync\Form\Element\ShowSyncStatus;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\StorageRepository;

use function define;
use function defined;

/**
 * ShowSyncStatusTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ShowSyncStatus::class)]
final class ShowSyncStatusTest extends TestCase
{
    private ShowSyncStatus $element;

    /** @var \PHPUnit\Framework\MockObject\MockObject&\Doctrine\DBAL\Result */
    private \PHPUnit\Framework\MockObject\MockObject $queryResult;

    protected function setUp(): void
    {
        $this->queryResult = $this->createMock(\Doctrine\DBAL\Result::class);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($this->queryResult);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $fileRepository = new FileRepository(
            $connectionPool,
            $this->createMock(ProcessedFileRepository::class),
            $this->createMock(StorageRepository::class),
        );

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_contains($key, 'sync_status.label') => 'File Sync',
                str_contains($key, 'remote_instance') => 'Remote Instance',
                str_contains($key, 'placeholder_image') => 'Placeholder Image',
                str_contains($key, 'sync_status.unknown') => '%s',
                str_contains($key, 'file_sync.status') => 'Sync Status',
                default => $key,
            },
        );
        $GLOBALS['LANG'] = $languageService;
        $GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'] = [0 => ['remote_instance' => []]];

        if (!defined('LF')) {
            define('LF', "\n");
        }

        $this->element = new ShowSyncStatus($fileRepository);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER'], $GLOBALS['TYPO3_CONF_VARS']);
    }

    #[Test]
    public function renderReturnsEmptyWhenFileSyncNotConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['storages'] = [];

        $this->setElementData('sys_file_metadata', 42);

        $result = $this->element->render();

        self::assertSame('', $result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsEmptyForFileWithoutSyncIdentifier(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn([
            'tx_typo3_file_sync_identifier' => '',
            'tx_typo3_file_sync_tstamp' => 0,
        ]);

        $this->setElementData('sys_file_metadata', 42);

        $result = $this->element->render();

        self::assertSame('', $result['html'] ?? '');
    }

    #[Test]
    public function renderReturnsEmptyForZeroFileUid(): void
    {
        $this->setElementData('sys_file_metadata', 0);

        $result = $this->element->render();

        self::assertSame('', $result['html'] ?? '');
    }

    #[Test]
    public function renderShowsRemoteInstanceInfo(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn([
            'tx_typo3_file_sync_identifier' => ResourceIdentifier::RemoteInstance->value,
            'tx_typo3_file_sync_tstamp' => 1700000000,
        ]);

        $this->setElementData('sys_file_metadata', 42);

        $result = $this->element->render();

        self::assertStringContainsString('File Sync', $result['html']);
        self::assertStringContainsString('Remote Instance', $result['html']);
        self::assertStringContainsString('background-color:#198754', $result['html']);
        self::assertStringContainsString('title="', $result['html']);
    }

    #[Test]
    public function renderShowsPlaceholderImageInfo(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn([
            'tx_typo3_file_sync_identifier' => ResourceIdentifier::PlaceholderImage->value,
            'tx_typo3_file_sync_tstamp' => 1700000000,
        ]);

        $this->setElementData('sys_file_metadata', 42);

        $result = $this->element->render();

        self::assertStringContainsString('Placeholder Image', $result['html']);
    }

    #[Test]
    public function renderShowsCustomIdentifierInfo(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn([
            'tx_typo3_file_sync_identifier' => 'my_custom_handler',
            'tx_typo3_file_sync_tstamp' => 0,
        ]);

        $this->setElementData('sys_file_metadata', 42);

        $result = $this->element->render();

        self::assertStringContainsString('my_custom_handler', $result['html']);
    }

    #[Test]
    public function renderHidesTimestampWhenZero(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn([
            'tx_typo3_file_sync_identifier' => ResourceIdentifier::RemoteInstance->value,
            'tx_typo3_file_sync_tstamp' => 0,
        ]);

        $this->setElementData('sys_file_metadata', 42);

        $result = $this->element->render();

        self::assertStringContainsString('Remote Instance', $result['html']);
        self::assertStringNotContainsString('title="', $result['html']);
    }

    #[Test]
    public function renderResolvesFileUidFromSysFile(): void
    {
        $this->queryResult->method('fetchAssociative')->willReturn([
            'tx_typo3_file_sync_identifier' => ResourceIdentifier::RemoteInstance->value,
            'tx_typo3_file_sync_tstamp' => 1700000000,
        ]);

        $this->setElementData('sys_file', 99, directUid: true);

        $result = $this->element->render();

        self::assertStringContainsString('Remote Instance', $result['html']);
    }

    private function setElementData(string $tableName, int $fileUid, bool $directUid = false): void
    {
        $databaseRow = $directUid
            ? ['uid' => $fileUid]
            : ['file' => [$fileUid]];

        $data = [
            'tableName' => $tableName,
            'databaseRow' => $databaseRow,
            'fieldName' => 'tx_typo3_file_sync_status',
            'parameterArray' => [
                'fieldConf' => [
                    'label' => 'Sync Status',
                    'config' => [
                        'type' => 'user',
                        'renderType' => 'showSyncStatus',
                    ],
                ],
            ],
            'renderData' => [
                'fieldInformation' => [],
                'fieldWizard' => [],
                'fieldControl' => [],
            ],
        ];

        // AbstractFormElement requires $this->data to be set via AbstractNode
        $reflection = new ReflectionClass(\TYPO3\CMS\Backend\Form\AbstractNode::class);
        $property = $reflection->getProperty('data');
        $property->setValue($this->element, $data);
    }
}
