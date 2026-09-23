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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Service;

use KonradMichalik\Typo3FileSync\Service\PublicUrlResolver;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\{Folder, ResourceStorage, StorageRepository};

/**
 * PublicUrlResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PublicUrlResolver::class)]
final class PublicUrlResolverTest extends TestCase
{
    #[Test]
    public function aStorageWithoutAPublicUrlContributesNoPrefix(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getRootLevelFolder')->willReturn($this->createMock(Folder::class));
        $storage->method('getPublicUrl')->willReturn(null);

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->with(1)->willReturn($storage);

        $subject = new PublicUrlResolver($storageRepository);

        self::assertSame([], $subject->identifiersByUrl(['/fileadmin/a.jpg'], [1]));
    }

    #[Test]
    public function aPublicUrlWithoutAPathContributesNoPrefix(): void
    {
        $storage = $this->createMock(ResourceStorage::class);
        $storage->method('getRootLevelFolder')->willReturn($this->createMock(Folder::class));
        $storage->method('getPublicUrl')->willReturn('https://cdn.example.com');

        $storageRepository = $this->createMock(StorageRepository::class);
        $storageRepository->method('getStorageObject')->with(1)->willReturn($storage);

        $subject = new PublicUrlResolver($storageRepository);

        self::assertSame([], $subject->identifiersByUrl(['/fileadmin/a.jpg'], [1]));
    }
}
