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

use Doctrine\DBAL\ParameterType;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Resource\ResourceIdentifier;
use TYPO3\CMS\Backend\Template\Components\Buttons\GenericButton;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\AbstractFile;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

use function method_exists;
use function sprintf;

/**
 * ProcessFileListActionsEventListener.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ProcessFileListActionsEventListener
{
    private const ACTION_NAME = 'file-sync-status';
    private const LLL_PREFIX = 'LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:';

    public function __construct(
        private IconFactory $iconFactory,
        private ConnectionPool $connectionPool,
    ) {}

    public function __invoke(ProcessFileListActionsEvent $event): void
    {
        if (!$event->isFile()) {
            return;
        }

        $resource = $event->getResource();
        if (!$resource instanceof AbstractFile) {
            return;
        }

        $identifier = $this->getSyncIdentifier($resource);
        if ('' === $identifier) {
            return;
        }

        $badge = $this->createBadge($identifier);

        // @phpstan-ignore function.alreadyNarrowedType
        if (method_exists($event, 'setAction')) {
            // TYPO3 v14+: ComponentGroup-based API
            $event->setAction(
                $badge,
                self::ACTION_NAME,
                \TYPO3\CMS\Backend\Template\Components\ActionGroup::primary,
            );
        } else {
            // TYPO3 v13: array-based API
            $actionItems = $event->getActionItems(); // @phpstan-ignore method.notFound
            $actionItems[self::ACTION_NAME] = $badge;
            $event->setActionItems($actionItems); // @phpstan-ignore method.notFound
        }
    }

    /**
     * Query the sync identifier directly from the database,
     * since TYPO3's FileIndexRepository only loads a fixed set of sys_file fields.
     */
    private function getSyncIdentifier(AbstractFile $file): string
    {
        $uid = $file->getUid();
        if (0 === $uid) {
            return '';
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file');

        $result = $queryBuilder
            ->select(Configuration::FIELD_IDENTIFIER)
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (string) ($result ?: '');
    }

    private function createBadge(string $identifier): GenericButton
    {
        $resolvedIdentifier = ResourceIdentifier::tryFrom($identifier);

        [$iconIdentifier, $labelKey] = match ($resolvedIdentifier) {
            ResourceIdentifier::RemoteInstance => ['actions-cloud', 'filelist.sync_status.remote_instance'],
            ResourceIdentifier::PlaceholderImage => ['actions-image', 'filelist.sync_status.placeholder_image'],
            default => ['actions-synchronize', 'filelist.sync_status.unknown'],
        };

        $label = $this->getLanguageService()->sL(self::LLL_PREFIX.$labelKey);

        if (null === $resolvedIdentifier) {
            $label = sprintf($label, $identifier);
        }

        $badge = new GenericButton();
        $badge->setTag('a');
        $badge->setLabel($label);
        $badge->setTitle($label);
        $badge->setIcon($this->iconFactory->getIcon($iconIdentifier, IconSize::SMALL));
        $badge->setShowLabelText(false);

        return $badge;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
