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

use Closure;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Resource\Driver\FileSyncDriver;
use KonradMichalik\Typo3FileSync\Resource\RemoteResourceCollectionFactory;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function sprintf;

/**
 * ResourceStorageInitializationEventListener.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
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
        $isLocalDriver = 'Local' === $storageRecord['driver'];
        $isRecordEnabled = ($storageRecord[Configuration::FIELD_ENABLE] ?? 0) > 0
            && ($storageRecord[Configuration::FIELD_RESOURCES] ?? '') !== '';
        $isStorageConfigured = isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES][$storage->getUid()])
            && [] !== $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES][$storage->getUid()];

        if (!$isLocalDriver || (!$isRecordEnabled && !$isStorageConfigured)) {
            if ($storage->getUid() > 0) {
                $this->logger?->debug(
                    sprintf('No file sync support for storage %s (%d) configured', $storage->getName(), $storage->getUid()),
                    [
                        'isLocalDriver' => $isLocalDriver,
                        'isRecordEnabled' => $isRecordEnabled,
                        'isStorageConfigured' => $isStorageConfigured,
                    ],
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
                $storageRecord[Configuration::FIELD_RESOURCES],
            );
        } else {
            $remoteResourceCollection = $this->remoteResourceCollectionFactory->createFromConfiguration(
                $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES][$storage->getUid()],
            );
        }

        /** @var FileSyncDriver $driverObject */
        $driverObject = GeneralUtility::makeInstance(
            FileSyncDriver::class,
            $storage->getConfiguration(),
            $originalDriverObject,
            $remoteResourceCollection,
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
        return Closure::bind(static fn () => $storage->driver, null, ResourceStorage::class)();
    }
}
