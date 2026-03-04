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
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Imaging\{IconFactory, IconSize};
use TYPO3\CMS\Core\Page\PageRenderer;

use function sprintf;

/**
 * ShowDeleteFiles.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ShowDeleteFiles extends AbstractFormElement
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

        $rows = $this->fileRepository->countByIdentifier($this->data['vanillaUid']);

        $html = [];
        $html[] = '<div class="row">';

        $languageService = $this->getLanguageService();
        if ([] === $rows) {
            $html[] = '<div class="form-group">';
            $html[] = '<div class="form-text">';
            $html[] = '<span class="badge badge-success">'
                .$languageService->sL('LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.no_delete')
                .'</span>';
            $html[] = '</div>';
            $html[] = '</div>';
        } else {
            $this->pageRenderer->loadJavaScriptModule('@konradmichalik/typo3-file-sync/form/storage-actions.js');
            foreach ($rows as $row) {
                $html[] = '<div class="form-group">';
                $html[] = '<div class="form-control-wrap">';
                $html[] = '<button type="button" class="btn btn-default t3js-file-sync-action"'
                    .' data-action="delete-files"'
                    .' data-storage-uid="'.(int) $this->data['vanillaUid'].'"'
                    .' data-identifier="'.htmlspecialchars($row[Configuration::FIELD_IDENTIFIER], \ENT_QUOTES).'">';
                $html[] = $this->iconFactory->getIcon('actions-edit-delete', IconSize::SMALL).' ';
                $html[] = sprintf(
                    $languageService->sL('LLL:EXT:typo3_file_sync/Resources/Private/Language/locallang_db.xlf:sys_file_storage.file_sync.delete_files'),
                    $row['count'],
                    $languageService->sL($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][Configuration::EXT_KEY][Configuration::EXTCONF_RESOURCE_HANDLER][$row[Configuration::FIELD_IDENTIFIER]]['title'] ?? $row[Configuration::FIELD_IDENTIFIER]),
                );
                $html[] = '</button>';
                $html[] = '</div>';
                $html[] = '</div>';
            }
        }
        $html[] = '</div>';

        $result['html'] = $this->wrapWithFieldsetAndLegend(implode('', $html));

        return $result;
    }
}
