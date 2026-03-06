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

namespace KonradMichalik\Typo3FileSync\Form\Element;

use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;

use function sprintf;

/**
 * ShowSyncStatus.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ShowSyncStatus extends AbstractFormElement
{
    private const LLL_PREFIX = 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:';

    public function __construct(
        protected readonly FileRepository $fileRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $result = $this->initializeResultArray();

        if (!$this->isFileSyncConfigured()) {
            return $result;
        }

        $fileUid = $this->resolveFileUid();
        if (0 === $fileUid) {
            return $result;
        }

        $syncData = $this->fileRepository->findSyncData($fileUid);
        if ('' === $syncData['identifier']) {
            return $result;
        }

        $result['html'] = $this->renderSyncInfo($syncData['identifier'], $syncData['tstamp']);

        return $result;
    }

    private function isFileSyncConfigured(): bool
    {
        $storages = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_STORAGES] ?? [];

        return [] !== $storages;
    }

    private function resolveFileUid(): int
    {
        if ('sys_file' === $this->data['tableName']) {
            return (int) $this->data['databaseRow']['uid'];
        }

        if ('sys_file_metadata' === $this->data['tableName']) {
            return (int) ($this->data['databaseRow']['file'][0] ?? 0);
        }

        return 0;
    }

    private function renderSyncInfo(string $identifier, int $tstamp): string
    {
        $resolvedIdentifier = ResourceIdentifier::tryFrom($identifier);
        $languageService = $this->getLanguageService();

        $labelKey = match ($resolvedIdentifier) {
            ResourceIdentifier::RemoteInstance => 'filelist.sync_status.remote_instance',
            ResourceIdentifier::PlaceholderImage => 'filelist.sync_status.placeholder_image',
            default => 'filelist.sync_status.unknown',
        };

        $handlerLabel = $languageService->sL(self::LLL_PREFIX.$labelKey);
        if (null === $resolvedIdentifier) {
            $handlerLabel = sprintf($handlerLabel, $identifier);
        }

        $generalLabel = htmlspecialchars($languageService->sL(self::LLL_PREFIX.'filelist.sync_status.label'), \ENT_QUOTES);
        $badgeText = htmlspecialchars($handlerLabel, \ENT_QUOTES);
        $titleAttr = '';

        if ($tstamp > 0) {
            $titleAttr = ' title="'.htmlspecialchars(date('d.m.Y H:i', $tstamp), \ENT_QUOTES).'"';
        }

        return '<div class="form-group">'
            .'<strong>'.$generalLabel.'</strong>'
            .' <span class="badge" style="background-color:#198754;color:#fff"'.$titleAttr.'>'
            .$badgeText
            .'</span>'
            .'</div>';
    }
}
