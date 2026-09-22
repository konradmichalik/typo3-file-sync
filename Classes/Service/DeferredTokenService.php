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

namespace KonradMichalik\Typo3FileSync\Service;

use TYPO3\CMS\Core\Crypto\HashService;

use function count;
use function hash_equals;

/**
 * DeferredTokenService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DeferredTokenService
{
    /**
     * Without a signature the materialize endpoint would be a lever to make
     * this instance fetch arbitrary paths from the remote.
     */
    private const SECRET = 'typo3_file_sync/deferred';

    public function __construct(private HashService $hashService) {}

    public function create(int $processedFileUid): string
    {
        return $processedFileUid.'.'.$this->hashService->hmac((string) $processedFileUid, self::SECRET);
    }

    public function resolve(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (2 !== count($parts)) {
            return null;
        }

        [$uid, $signature] = $parts;
        if ('' === $signature || 1 !== preg_match('/^[1-9]\d*$/', $uid)) {
            return null;
        }

        if (!hash_equals($this->hashService->hmac($uid, self::SECRET), $signature)) {
            return null;
        }

        return (int) $uid;
    }
}
