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

namespace KonradMichalik\Typo3FileSync\Controller;

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

use function is_array;
use function sprintf;

/**
 * StorageController.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsController]
final readonly class StorageController
{
    public function __construct(
        private FileRepository $fileRepository,
    ) {}

    public function resetMissingAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request body'], 400);
        }
        $storageUid = (int) ($body['storageUid'] ?? 0);

        if ($storageUid <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid storage UID'], 400);
        }

        $affectedRows = $this->fileRepository->resetMissing($storageUid);

        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Reset %d file(s)', $affectedRows),
            'count' => $affectedRows,
        ]);
    }

    public function deleteFilesAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid request body'], 400);
        }
        $storageUid = (int) ($body['storageUid'] ?? 0);
        $identifier = (string) ($body['identifier'] ?? '');

        if ($storageUid <= 0 || '' === $identifier) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid storage UID or identifier'], 400);
        }

        $deletedCount = $this->fileRepository->deleteByIdentifier($identifier, $storageUid);

        return new JsonResponse([
            'success' => true,
            'message' => sprintf('Deleted %d file(s)', $deletedCount),
            'count' => $deletedCount,
        ]);
    }
}
