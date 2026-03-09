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

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Page\PageRenderer;

use function sprintf;

/**
 * ShowMissingFiles.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ShowMissingFiles extends AbstractFormElement
{
    public function __construct(
        protected readonly FileRepository $fileRepository,
        protected readonly IconFactory $iconFactory,
        protected readonly PageRenderer $pageRenderer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function render(): array
    {
        $result = $this->initializeResultArray();

        $count = $this->fileRepository->countMissing((int) $this->data['vanillaUid']);

        $html = [];
        $html[] = '<div class="form-group">';

        $languageService = $this->getLanguageService();
        if (0 === $count) {
            $html[] = '<div class="form-text">';
            $html[] = '<span class="badge badge-success">'
                .$languageService->sL('LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.no_missing')
                .'</span>'
                .'</div>';
        } else {
            $this->pageRenderer->loadJavaScriptModule('@konradmichalik/typo3-file-sync/form/storage-actions.js');
            $html[] = '<div class="form-control-wrap">';
            $html[] = '<button type="button" class="btn btn-default t3js-file-sync-action"'
                .' data-action="reset-missing"'
                .' data-storage-uid="'.(int) $this->data['vanillaUid'].'">';
            $html[] = $this->iconFactory->getIcon('actions-database-reload', IconSize::SMALL);
            $html[] = ' '.$languageService->sL('LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.reset');
            $html[] = '</button>';
            $html[] = '</div>';
            $html[] = '<div class="form-text">';
            $html[] = '<span class="badge badge-danger">'
                .sprintf(
                    $languageService->sL('LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.missing_files'),
                    $count,
                )
                .'</span>';
            $html[] = '</div>';
        }

        $html[] = '</div>';

        $result['html'] = $this->wrapWithFieldsetAndLegend(implode('', $html));

        return $result;
    }
}
