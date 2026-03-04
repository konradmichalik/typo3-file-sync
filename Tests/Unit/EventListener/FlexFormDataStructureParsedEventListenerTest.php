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

use KonradMichalik\Typo3FileSync\EventListener\FlexFormDataStructureParsedEventListener;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Event\AfterFlexFormDataStructureParsedEvent;


/**
 * FlexFormDataStructureParsedEventListenerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

#[CoversClass(FlexFormDataStructureParsedEventListener::class)]
final class FlexFormDataStructureParsedEventListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = [
            'remote_instance' => [
                'title' => 'Remote Instance',
                'config' => ['label' => 'URL', 'config' => ['type' => 'input']],
                'handler' => 'SomeHandler',
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']);
    }

    #[Test]
    public function listenerSkipsUnrelatedTable(): void
    {
        $identifier = ['tableName' => 'tt_content', 'fieldName' => 'pi_flexform'];
        $dataStructure = ['sheets' => []];

        $event = new AfterFlexFormDataStructureParsedEvent($dataStructure, $identifier);

        $listener = new FlexFormDataStructureParsedEventListener();
        $listener($event);

        self::assertSame(['sheets' => []], $event->getDataStructure());
    }

    #[Test]
    public function listenerSkipsUnrelatedField(): void
    {
        $identifier = ['tableName' => 'sys_file_storage', 'fieldName' => 'other_field'];
        $dataStructure = ['sheets' => []];

        $event = new AfterFlexFormDataStructureParsedEvent($dataStructure, $identifier);

        $listener = new FlexFormDataStructureParsedEventListener();
        $listener($event);

        self::assertSame(['sheets' => []], $event->getDataStructure());
    }

    #[Test]
    public function listenerBuildsDataStructureFromRegisteredHandlers(): void
    {
        $identifier = ['tableName' => 'sys_file_storage', 'fieldName' => 'tx_typo3_file_sync_resources'];
        $dataStructure = [
            'sheets' => [
                'sDEF' => [
                    'ROOT' => [
                        'el' => [
                            'resources' => [
                                'el' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $event = new AfterFlexFormDataStructureParsedEvent($dataStructure, $identifier);

        $listener = new FlexFormDataStructureParsedEventListener();
        $listener($event);

        $result = $event->getDataStructure();
        $resources = $result['sheets']['sDEF']['ROOT']['el']['resources']['el'];

        self::assertArrayHasKey('remote_instance', $resources);
        self::assertSame('Remote Instance', $resources['remote_instance']['title']);
        self::assertSame('array', $resources['remote_instance']['type']);
    }

    #[Test]
    public function listenerSkipsIncompleteHandlerConfiguration(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler']['incomplete'] = [
            'title' => 'Incomplete',
            // Missing 'config' and 'handler'
        ];

        $identifier = ['tableName' => 'sys_file_storage', 'fieldName' => 'tx_typo3_file_sync_resources'];
        $dataStructure = [
            'sheets' => [
                'sDEF' => [
                    'ROOT' => [
                        'el' => [
                            'resources' => [
                                'el' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $event = new AfterFlexFormDataStructureParsedEvent($dataStructure, $identifier);

        $listener = new FlexFormDataStructureParsedEventListener();
        $listener($event);

        $result = $event->getDataStructure();
        $resources = $result['sheets']['sDEF']['ROOT']['el']['resources']['el'];

        self::assertArrayNotHasKey('incomplete', $resources);
        self::assertArrayHasKey('remote_instance', $resources);
    }
}
