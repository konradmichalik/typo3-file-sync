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

use Doctrine\DBAL\ParameterType;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};

use function sprintf;

/**
 * ShowSyncStatus.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ShowSyncStatus extends AbstractFormElement
{
    private const LLL_PREFIX = 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:';

    public function __construct(
        protected readonly ConnectionPool $connectionPool,
        protected readonly IconFactory $iconFactory,
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

        $syncData = $this->fetchSyncData($fileUid);
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

    /**
     * @return array{identifier: string, tstamp: int}
     */
    private function fetchSyncData(int $fileUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');

        $row = $queryBuilder
            ->select(Configuration::FIELD_IDENTIFIER, Configuration::FIELD_TSTAMP)
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($fileUid, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return ['identifier' => '', 'tstamp' => 0];
        }

        return [
            'identifier' => (string) ($row[Configuration::FIELD_IDENTIFIER] ?? ''),
            'tstamp' => (int) ($row[Configuration::FIELD_TSTAMP] ?? 0),
        ];
    }

    private function renderSyncInfo(string $identifier, int $tstamp): string
    {
        $resolvedIdentifier = ResourceIdentifier::tryFrom($identifier);
        $languageService = $this->getLanguageService();

        [$iconIdentifier, $labelKey] = match ($resolvedIdentifier) {
            ResourceIdentifier::RemoteInstance => ['actions-cloud', 'filelist.sync_status.remote_instance'],
            ResourceIdentifier::PlaceholderImage => ['actions-image', 'filelist.sync_status.placeholder_image'],
            default => ['actions-synchronize', 'filelist.sync_status.unknown'],
        };

        $label = $languageService->sL(self::LLL_PREFIX.$labelKey);

        if (null === $resolvedIdentifier) {
            $label = sprintf($label, $identifier);
        }

        $icon = $this->iconFactory->getIcon($iconIdentifier, IconSize::SMALL);

        $badgeText = htmlspecialchars($label, \ENT_QUOTES);

        if ($tstamp > 0) {
            $formattedDate = date('d.m.Y H:i', $tstamp);
            $badgeText .= ' &middot; '.htmlspecialchars($formattedDate, \ENT_QUOTES);
        }

        return '<div class="form-group">'
            .'<span class="badge badge-info d-inline-flex align-items-center gap-1">'
            .$icon.$badgeText
            .'</span>'
            .'</div>';
    }
}
