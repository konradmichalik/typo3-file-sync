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

namespace KonradMichalik\Typo3FileSync\EventListener;

use KonradMichalik\Typo3FileSync\Configuration;
use TYPO3\CMS\Core\Configuration\Event\AfterFlexFormDataStructureParsedEvent;

/**
 * FlexFormDataStructureParsedEventListener.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FlexFormDataStructureParsedEventListener
{
    public function __invoke(AfterFlexFormDataStructureParsedEvent $event): void
    {
        $identifier = $event->getIdentifier();
        if (($identifier['tableName'] ?? '') !== 'sys_file_storage'
            || ($identifier['fieldName'] ?? '') !== Configuration::FIELD_RESOURCES
        ) {
            return;
        }

        $dataStructure = $event->getDataStructure();
        $dataStructure['sheets']['sDEF']['ROOT']['el']['resources']['el'] = [];

        foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_RESOURCE_HANDLER] ?? [] as $resource => $configuration) {
            if (($configuration['title'] ?? '') === ''
                || !isset($configuration['config'])
                || ($configuration['handler'] ?? '') === ''
            ) {
                continue;
            }

            $dataStructure['sheets']['sDEF']['ROOT']['el']['resources']['el'][$resource] = [
                'el' => [
                    $resource => $configuration['config'],
                ],
                'title' => $configuration['title'],
                'type' => 'array',
            ];
        }

        $event->setDataStructure($dataStructure);
    }
}
