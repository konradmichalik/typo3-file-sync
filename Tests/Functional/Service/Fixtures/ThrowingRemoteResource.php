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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Service\Fixtures;

use KonradMichalik\Typo3FileSync\Resource\{BatchRemoteResourceInterface, RemoteResourceInterface};
use RuntimeException;
use TYPO3\CMS\Core\Resource\FileInterface;

/**
 * ThrowingRemoteResource.
 *
 * A resource handler that fails every call, for the tests that model a
 * project's own broken handler rather than this extension's own remote or
 * placeholder ones, neither of which ever throws.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ThrowingRemoteResource implements BatchRemoteResourceInterface, RemoteResourceInterface
{
    public function __construct(mixed $configuration = null) {}

    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): mixed
    {
        throw new RuntimeException('ThrowingRemoteResource always fails.');
    }

    public function prefetch(array $filePaths): void
    {
        throw new RuntimeException('ThrowingRemoteResource always fails.');
    }
}
