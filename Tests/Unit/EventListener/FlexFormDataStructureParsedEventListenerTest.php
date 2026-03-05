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
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Event\AfterFlexFormDataStructureParsedEvent;

/**
 * FlexFormDataStructureParsedEventListenerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(FlexFormDataStructureParsedEventListener::class)]
final class FlexFormDataStructureParsedEventListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['typo3_file_sync']['resourceHandler'] = [
            ResourceIdentifier::RemoteInstance->value => [
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

        self::assertArrayHasKey(ResourceIdentifier::RemoteInstance->value, $resources);
        self::assertSame('Remote Instance', $resources[ResourceIdentifier::RemoteInstance->value]['title']);
        self::assertSame('array', $resources[ResourceIdentifier::RemoteInstance->value]['type']);
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
        self::assertArrayHasKey(ResourceIdentifier::RemoteInstance->value, $resources);
    }
}
