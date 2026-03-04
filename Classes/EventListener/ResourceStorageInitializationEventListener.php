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

namespace KonradMichalik\Typo3FileSync\EventListener;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollectionFactory;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ResourceStorageInitializationEventListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly RemoteResourceCollectionFactory $remoteResourceCollectionFactory,
    ) {}

    public function __invoke(AfterResourceStorageInitializationEvent $event): void
    {
        $storage = $event->getStorage();
        $storageRecord = $storage->getStorageRecord();
        $isLocalDriver = $storageRecord['driver'] === 'Local';
        $isRecordEnabled = !empty($storageRecord['tx_typo3_file_sync_enable']) && !empty($storageRecord['tx_typo3_file_sync_resources']);
        $isStorageConfigured = !empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['storages'][$storage->getUid()]);

        if (!$isLocalDriver || (!$isRecordEnabled && !$isStorageConfigured)) {
            if ($storage->getUid() > 0) {
                $this->logger->info(
                    sprintf('No file sync support for storage %s (%d) configured', $storage->getName(), $storage->getUid()),
                    [
                        'isLocalDriver' => $isLocalDriver,
                        'isRecordEnabled' => $isRecordEnabled,
                        'isStorageConfigured' => $isStorageConfigured,
                    ]
                );
            }

            return;
        }

        $originalDriverObject = self::getOriginalDriver($storage);

        if ($originalDriverObject instanceof FileSyncDriver) {
            return;
        }

        if ($isRecordEnabled) {
            $remoteResourceCollection = $this->remoteResourceCollectionFactory->createFromFlexForm(
                $storageRecord['tx_typo3_file_sync_resources']
            );
        } else {
            $remoteResourceCollection = $this->remoteResourceCollectionFactory->createFromConfiguration(
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY]['storages'][$storage->getUid()]
            );
        }

        $driverObject = GeneralUtility::makeInstance(
            FileSyncDriver::class,
            $storage->getConfiguration(),
            $originalDriverObject,
            $remoteResourceCollection
        );
        $driverObject->setStorageUid($storageRecord['uid']);
        $driverObject->mergeConfigurationCapabilities($storage->getCapabilities());
        try {
            $driverObject->processConfiguration();
        } catch (InvalidConfigurationException) {
            // Intended fallthrough
        }
        $driverObject->initialize();

        $storage->setDriver($driverObject);
    }

    /**
     * TYPO3 core deliberately keeps the driver private with no public accessor.
     *
     * @see ResourceStorage::$driver (private)
     * @see ResourceStorage::getDriver() (protected)
     */
    private static function getOriginalDriver(ResourceStorage $storage): DriverInterface
    {
        return \Closure::bind(static fn () => $storage->driver, null, ResourceStorage::class)();
    }
}
