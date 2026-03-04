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

namespace KonradMichalik\Typo3FileSync\Resource;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Exception\{MissingInterfaceException, UnknownResourceException};
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use TYPO3\CMS\Core\Resource\{ResourceFactory, StorageRepository};
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_array;

/**
 * RemoteResourceCollectionFactory.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RemoteResourceCollectionFactory
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ResourceFactory $resourceFactory,
        private FileRepository $fileRepository,
    ) {}

    /**
     * @param array<int, array{identifier?: string, configuration?: mixed}> $configuration
     */
    public function createFromConfiguration(array $configuration): RemoteResourceCollection
    {
        $remoteResources = [];

        foreach ($configuration as $resource) {
            if (($resource['identifier'] ?? '') === '') {
                continue;
            }

            $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['resourceHandler'] ?? [];
            if (!isset($extConf[$resource['identifier']]['handler'])) {
                throw new UnknownResourceException('Unexpected File Sync Resource configuration "'.$resource['identifier'].'"', 1519788775);
            }

            /** @var object $handler */
            $handler = GeneralUtility::makeInstance( // @phpstan-ignore argument.templateType
                $extConf[$resource['identifier']]['handler'],
                $resource['configuration'] ?? null,
            );

            if (!$handler instanceof RemoteResourceInterface) {
                throw new MissingInterfaceException('Resource handler for "'.$resource['identifier'].'" doesn\'t implement '.RemoteResourceInterface::class, 1556472885);
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
            $this->fileRepository,
        );
    }

    public function createFromFlexForm(string $flexForm): RemoteResourceCollection
    {
        $configuration = [];
        $resourcesConfiguration = GeneralUtility::xml2array($flexForm);

        foreach ((array) ($resourcesConfiguration['data']['sDEF']['lDEF']['resources']['el'] ?? []) as $resource) {
            if (!is_array($resource) || [] === $resource) {
                continue;
            }

            $identifier = key($resource);
            $extConf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['resourceHandler'] ?? [];

            if (!isset($extConf[$identifier])) {
                throw new UnknownResourceException('Unexpected File Sync Resource configuration "'.$identifier.'"', 1528326468);
            }

            $configuration[] = [
                'identifier' => $identifier,
                'configuration' => $resource[$identifier]['el'][$identifier]['vDEF'] ?? null,
            ];
        }

        return $this->createFromConfiguration($configuration);
    }
}
