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

use KonradMichalik\Typo3FileSync\Form\Element\ShowMissingFiles;
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
 * ShowMissingFilesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(ShowMissingFiles::class)]
final class ShowMissingFilesTest extends TestCase
{
    private ShowMissingFiles $element;

    private MockObject&\Doctrine\DBAL\Result $queryResult;

    private PageRenderer&MockObject $pageRenderer;

    private int $missingCount = 0;

    protected function setUp(): void
    {
        $this->queryResult = $this->createMock(\Doctrine\DBAL\Result::class);
        $this->queryResult->method('fetchOne')->willReturnCallback(fn (): int => $this->missingCount);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
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

        $this->element = new ShowMissingFiles($fileRepository, $iconFactory, $this->pageRenderer);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
    }

    #[Test]
    public function renderShowsSuccessBadgeWhenNoMissingFiles(): void
    {
        $this->missingCount = 0;
        $this->pageRenderer->expects(self::never())->method('loadJavaScriptModule');

        $this->setElementData(5);

        $result = $this->element->render();

        self::assertStringContainsString('badge-success', $result['html']);
    }

    #[Test]
    public function renderShowsResetButtonAndCountWhenFilesAreMissing(): void
    {
        $this->missingCount = 4;
        $this->pageRenderer->expects(self::once())->method('loadJavaScriptModule');

        $this->setElementData(5);

        $result = $this->element->render();

        self::assertStringContainsString('data-action="reset-missing"', $result['html']);
        self::assertStringContainsString('badge-danger', $result['html']);
    }

    private function setElementData(int $vanillaUid): void
    {
        $data = [
            'vanillaUid' => $vanillaUid,
            'tableName' => 'sys_file_storage',
            'databaseRow' => ['uid' => $vanillaUid],
            'fieldName' => 'tx_typo3_file_sync_missing',
            'parameterArray' => [
                'fieldConf' => [
                    'label' => 'Missing Files',
                    'config' => ['type' => 'user', 'renderType' => 'fileSyncShowMissingFiles'],
                ],
            ],
            'renderData' => ['fieldInformation' => [], 'fieldWizard' => [], 'fieldControl' => []],
        ];

        $reflection = new ReflectionClass(\TYPO3\CMS\Backend\Form\AbstractNode::class);
        $property = $reflection->getProperty('data');
        $property->setValue($this->element, $data);
    }
}
