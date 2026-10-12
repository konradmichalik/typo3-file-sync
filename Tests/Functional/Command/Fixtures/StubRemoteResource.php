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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Command\Fixtures;

use KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface;
use RuntimeException;
use TYPO3\CMS\Core\Resource\FileInterface;

use function basename;
use function str_ends_with;

/**
 * StubRemoteResource.
 *
 * A remote that delivers every file named remote.jpg, throws for boom.jpg and
 * knows nothing else. Matched by name rather than path, because the path the
 * driver hands over depends on how the core builds public URLs.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class StubRemoteResource implements RemoteResourceInterface
{
    /** @var list<string> */
    public static array $requested = [];

    public function __construct(mixed $configuration = null) {}

    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): mixed
    {
        self::$requested[] = $fileIdentifier;

        if (str_ends_with($filePath, 'boom.jpg')) {
            throw new RuntimeException('StubRemoteResource failed on purpose.', 1760270000);
        }

        return 'remote.jpg' === basename($filePath) ? 'remote-content' : false;
    }
}
