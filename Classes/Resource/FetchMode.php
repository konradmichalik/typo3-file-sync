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

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * FetchMode.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FetchMode implements SingletonInterface
{
    private bool $synchronousForced = false;

    /**
     * @var array<int, bool>
     */
    private array $storages = [];

    public function registerStorage(int $storageUid, bool $deferred): void
    {
        $this->storages[$storageUid] = $deferred;
    }

    public function isDeferred(int $storageUid): bool
    {
        if ($this->synchronousForced) {
            return false;
        }

        return ($this->storages[$storageUid] ?? false) && $this->isFrontendRequest();
    }

    /**
     * The materialize endpoint runs inside a frontend request but must fetch
     * for real, otherwise it would defer its own work and never do any.
     */
    public function forceSynchronous(): void
    {
        $this->synchronousForced = true;
    }

    private function isFrontendRequest(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface && ApplicationType::fromRequest($request)->isFrontend();
    }
}
