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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\EventListener;

use KonradMichalik\Typo3FileSync\EventListener\ProcessFileListActionsEventListener;
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Backend\Template\Components\ComponentGroup;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Imaging\{Icon, IconFactory, IconRegistry};
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\{AbstractFile, Folder};
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * ProcessFileListActionsEventListenerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[CoversClass(ProcessFileListActionsEventListener::class)]
final class ProcessFileListActionsEventListenerTest extends TestCase
{
    private ProcessFileListActionsEventListener $listener;

    private QueryBuilder $queryBuilder;

    /** @var \PHPUnit\Framework\MockObject\MockObject&\Doctrine\DBAL\Result */
    private \PHPUnit\Framework\MockObject\MockObject $queryResult;

    protected function setUp(): void
    {
        $icon = $this->createMock(Icon::class);
        $icon->method('render')->willReturn('<span class="icon"></span>');

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn($icon);

        $iconFactory = new IconFactory(
            $this->createMock(\Psr\EventDispatcher\EventDispatcherInterface::class),
            $this->createMock(IconRegistry::class),
            $this->createMock(\Psr\Container\ContainerInterface::class),
            $cache,
        );

        $this->queryResult = $this->createMock(\Doctrine\DBAL\Result::class);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('expr')->willReturn($expressionBuilder);
        $this->queryBuilder->method('createNamedParameter')->willReturn('1');
        $this->queryBuilder->method('executeQuery')->willReturn($this->queryResult);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($this->queryBuilder);

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('sL')->willReturnCallback(
            static fn (string $key): string => match (true) {
                str_contains($key, 'remote_instance') => 'Synced from remote instance',
                str_contains($key, 'placeholder_image') => 'Generated as placeholder image',
                str_contains($key, 'unknown') => 'Synced via: %s',
                default => $key,
            },
        );
        $GLOBALS['LANG'] = $languageService;

        $this->listener = new ProcessFileListActionsEventListener($iconFactory, $connectionPool);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
    }

    #[Test]
    public function listenerSkipsFolders(): void
    {
        $folder = $this->createMock(Folder::class);
        $event = $this->createEvent($folder);

        ($this->listener)($event);

        self::assertFalse($this->eventHasAction($event, 'file-sync-status'));
    }

    #[Test]
    public function listenerSkipsFilesWithEmptyIdentifier(): void
    {
        $file = $this->createFileMockWithUid(100);
        $this->queryResult->method('fetchOne')->willReturn('');

        $event = $this->createEvent($file);

        ($this->listener)($event);

        self::assertFalse($this->eventHasAction($event, 'file-sync-status'));
    }

    #[Test]
    public function listenerSkipsFilesWithZeroUid(): void
    {
        $file = $this->createFileMockWithUid(0);

        $event = $this->createEvent($file);

        ($this->listener)($event);

        self::assertFalse($this->eventHasAction($event, 'file-sync-status'));
    }

    #[Test]
    public function listenerAddsBadgeForRemoteInstance(): void
    {
        $file = $this->createFileMockWithUid(100);
        $this->queryResult->method('fetchOne')->willReturn(ResourceIdentifier::RemoteInstance->value);

        $event = $this->createEvent($file);

        ($this->listener)($event);

        self::assertTrue($this->eventHasAction($event, 'file-sync-status'));
        self::assertInstanceOf(GenericButton::class, $this->eventGetAction($event, 'file-sync-status'));
    }

    #[Test]
    public function listenerAddsBadgeForPlaceholderImage(): void
    {
        $file = $this->createFileMockWithUid(101);
        $this->queryResult->method('fetchOne')->willReturn(ResourceIdentifier::PlaceholderImage->value);

        $event = $this->createEvent($file);

        ($this->listener)($event);

        self::assertTrue($this->eventHasAction($event, 'file-sync-status'));
        self::assertInstanceOf(GenericButton::class, $this->eventGetAction($event, 'file-sync-status'));
    }

    #[Test]
    public function listenerAddsBadgeForCustomIdentifier(): void
    {
        $file = $this->createFileMockWithUid(102);
        $this->queryResult->method('fetchOne')->willReturn('my_custom_handler');

        $event = $this->createEvent($file);

        ($this->listener)($event);

        self::assertTrue($this->eventHasAction($event, 'file-sync-status'));
        self::assertInstanceOf(GenericButton::class, $this->eventGetAction($event, 'file-sync-status'));
    }

    private function createFileMockWithUid(int $uid): AbstractFile
    {
        $file = $this->createMock(AbstractFile::class);
        $file->method('getUid')->willReturn($uid);

        return $file;
    }

    private function eventHasAction(ProcessFileListActionsEvent $event, string $name): bool
    {
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($event, 'hasAction')) {
            return $event->hasAction($name);
        }

        return isset($event->getActionItems()[$name]); // @phpstan-ignore method.notFound
    }

    private function eventGetAction(ProcessFileListActionsEvent $event, string $name): mixed
    {
        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($event, 'getAction')) {
            return $event->getAction($name);
        }

        return $event->getActionItems()[$name] ?? null; // @phpstan-ignore method.notFound
    }

    private function createEvent(Folder|AbstractFile $resource): ProcessFileListActionsEvent
    {
        if (class_exists(ComponentGroup::class)) {
            // TYPO3 v14+
            $primary = new ComponentGroup('primary');
            $secondary = new ComponentGroup('secondary');
            $request = $this->createMock(ServerRequestInterface::class);

            return new ProcessFileListActionsEvent($primary, $secondary, $resource, $request);
        }

        // TYPO3 v13
        return new ProcessFileListActionsEvent($resource, []); // @phpstan-ignore arguments.count, argument.type, argument.type
    }
}
