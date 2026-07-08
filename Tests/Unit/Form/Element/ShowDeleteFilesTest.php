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

use KonradMichalik\Typo3FileSync\Form\Element\ShowDeleteFiles;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Imaging\{Icon, IconFactory};
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

use function define;
use function defined;

/**
 * ShowDeleteFilesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ShowDeleteFiles::class)]
final class ShowDeleteFilesTest extends TestCase
{
    private ShowDeleteFiles $element;

    private MockObject&\Doctrine\DBAL\Result $queryResult;

    private PageRenderer&MockObject $pageRenderer;

    protected function setUp(): void
    {
        $this->queryResult = $this->createMock(\Doctrine\DBAL\Result::class);

        $doctrineQueryBuilder = $this->createMock(\Doctrine\DBAL\Query\QueryBuilder::class);
        $doctrineQueryBuilder->method('select')->willReturnSelf();

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('neq')->willReturn('1=1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getConcreteQueryBuilder')->willReturn($doctrineQueryBuilder);
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('groupBy')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn('1');
        $queryBuilder->method('executeQuery')->willReturn($this->queryResult);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $fileRepository = (new ReflectionClass(FileRepository::class))->newInstanceWithoutConstructor();
        $connectionPoolProperty = (new ReflectionClass(FileRepository::class))->getProperty('connectionPool');
        $connectionPoolProperty->setValue($fileRepository, $connectionPool);

        $iconFactory = $this->createMock(IconFactory::class);
        $iconFactory->method('getIcon')->willReturn($this->createMock(Icon::class));

        $this->pageRenderer = $this->createMock(PageRenderer::class);

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);
        $GLOBALS['LANG'] = $languageService;
        $GLOBALS['BE_USER'] = $this->createMock(BackendUserAuthentication::class);

        if (!defined('LF')) {
            define('LF', "\n");
        }

        $this->element = new ShowDeleteFiles($fileRepository, $iconFactory, $this->pageRenderer);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
    }

    #[Test]
    public function renderShowsSuccessBadgeWhenNoFilesToDelete(): void
    {
        $this->queryResult->method('fetchAllAssociative')->willReturn([]);
        $this->pageRenderer->expects(self::never())->method('loadJavaScriptModule');

        $this->setElementData(5);

        $result = $this->element->render();

        self::assertStringContainsString('badge-success', $result['html']);
    }

    #[Test]
    public function renderShowsDeleteButtonForEachIdentifierGroup(): void
    {
        $this->queryResult->method('fetchAllAssociative')->willReturn([
            ['count' => 3, 'tx_typo3_file_sync_identifier' => 'remote_instance'],
        ]);
        $this->pageRenderer->expects(self::once())->method('loadJavaScriptModule');

        $this->setElementData(5);

        $result = $this->element->render();

        self::assertStringContainsString('data-action="delete-files"', $result['html']);
        self::assertStringContainsString('data-identifier="remote_instance"', $result['html']);
    }

    private function setElementData(int $vanillaUid): void
    {
        $data = [
            'vanillaUid' => $vanillaUid,
            'tableName' => 'sys_file_storage',
            'databaseRow' => ['uid' => $vanillaUid],
            'fieldName' => 'tx_typo3_file_sync_delete',
            'parameterArray' => [
                'fieldConf' => [
                    'label' => 'Delete Files',
                    'config' => ['type' => 'user', 'renderType' => 'fileSyncShowDeleteFiles'],
                ],
            ],
            'renderData' => ['fieldInformation' => [], 'fieldWizard' => [], 'fieldControl' => []],
        ];

        $reflection = new ReflectionClass(\TYPO3\CMS\Backend\Form\AbstractNode::class);
        $property = $reflection->getProperty('data');
        $property->setValue($this->element, $data);
    }
}
