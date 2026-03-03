<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_file_sync" TYPO3 CMS extension.
 *
 * (c) 2025 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3FileSync\Resource;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Exception\MissingInterfaceException;
use KonradMichalik\Typo3FileSync\Exception\UnknownResourceException;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteResourceCollectionFactory
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly ResourceFactory $resourceFactory,
        private readonly FileRepository $fileRepository,
    ) {}

    /**
     * @param array<int, array{identifier?: string, configuration?: mixed}> $configuration
     */
    public function createFromConfiguration(array $configuration): RemoteResourceCollection
    {
        $remoteResources = [];

        foreach ($configuration as $resource) {
            if (empty($resource['identifier'])) {
                continue;
            }

            $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['resourceHandler'] ?? [];
            if (!isset($extConf[$resource['identifier']]['handler'])) {
                throw new UnknownResourceException(
                    'Unexpected File Sync Resource configuration "' . $resource['identifier'] . '"',
                    1519788775
                );
            }

            $handler = GeneralUtility::makeInstance(
                $extConf[$resource['identifier']]['handler'],
                $resource['configuration'] ?? null
            );

            if (!$handler instanceof RemoteResourceInterface) {
                throw new MissingInterfaceException(
                    'Resource handler for "' . $resource['identifier'] . '" doesn\'t implement ' . RemoteResourceInterface::class,
                    1556472885
                );
            }

            $remoteResources[] = [
                'identifier' => $resource['identifier'],
                'handler' => $handler,
            ];
        }

        return GeneralUtility::makeInstance(
            RemoteResourceCollection::class,
            $remoteResources,
            $this->storageRepository,
            $this->resourceFactory,
            $this->fileRepository
        );
    }

    public function createFromFlexForm(string $flexForm): RemoteResourceCollection
    {
        $configuration = [];
        $resourcesConfiguration = GeneralUtility::xml2array($flexForm);

        foreach ((array)($resourcesConfiguration['data']['sDEF']['lDEF']['resources']['el'] ?? []) as $resource) {
            if (empty($resource)) {
                continue;
            }

            $identifier = key($resource);
            $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['resourceHandler'] ?? [];

            if (!isset($extConf[$identifier])) {
                throw new UnknownResourceException(
                    'Unexpected File Sync Resource configuration "' . $identifier . '"',
                    1528326468
                );
            }

            $configuration[] = [
                'identifier' => $identifier,
                'configuration' => $resource[$identifier]['el'][$identifier]['vDEF'] ?? null,
            ];
        }

        return $this->createFromConfiguration($configuration);
    }
}
