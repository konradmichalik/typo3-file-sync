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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource\Handler;

use KonradMichalik\Typo3FileSync\Resource\Handler\PlaceholderImageResource;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileInterface;

use function function_exists;

/**
 * PlaceholderImageResourceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PlaceholderImageResource::class)]
final class PlaceholderImageResourceTest extends TestCase
{
    #[Test]
    public function hasFileReturnsTrueForImageFileObject(): void
    {
        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn('jpg');

        $resource = new PlaceholderImageResource('#CCCCCC, #969696');
        self::assertTrue($resource->hasFile('/test.jpg', 'fileadmin/test.jpg', $fileObject));
    }

    #[Test]
    public function hasFileReturnsFalseForNonImageExtension(): void
    {
        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn('pdf');

        $resource = new PlaceholderImageResource('#CCCCCC, #969696');
        self::assertFalse($resource->hasFile('/test.pdf', 'fileadmin/test.pdf', $fileObject));
    }

    #[Test]
    public function hasFileReturnsFalseWithoutFileObject(): void
    {
        $resource = new PlaceholderImageResource('#CCCCCC, #969696');
        self::assertFalse($resource->hasFile('/test.jpg', 'fileadmin/test.jpg'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportedExtensionsDataProvider(): array
    {
        return [
            'avif' => ['avif'],
            'gif' => ['gif'],
            'jpeg' => ['jpeg'],
            'jpg' => ['jpg'],
            'png' => ['png'],
            'svg' => ['svg'],
            'webp' => ['webp'],
        ];
    }

    #[Test]
    #[DataProvider('supportedExtensionsDataProvider')]
    public function hasFileReturnsTrueForAllSupportedExtensions(string $extension): void
    {
        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn($extension);

        $resource = new PlaceholderImageResource('#CCCCCC, #969696');
        self::assertTrue($resource->hasFile('/test.'.$extension, 'fileadmin/test.'.$extension, $fileObject));
    }

    #[Test]
    public function getFileReturnsSvgForSvgExtension(): void
    {
        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn('svg');
        $fileObject->method('getProperty')->willReturnMap([
            ['width', 200],
            ['height', 100],
        ]);

        $resource = new PlaceholderImageResource('#CCCCCC, #969696');
        $result = $resource->getFile('/test.svg', 'fileadmin/test.svg', $fileObject);

        self::assertIsString($result);
        self::assertStringContainsString('<svg', $result);
        self::assertStringContainsString('200', $result);
        self::assertStringContainsString('100', $result);
        self::assertStringContainsString('#CCCCCC', $result);
        self::assertStringContainsString('#969696', $result);
        self::assertStringContainsString('200 x 100', $result);
    }

    #[Test]
    public function getFileReturnsGdImageForPng(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD extension not available');
        }

        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn('png');
        $fileObject->method('getProperty')->willReturnMap([
            ['width', 100],
            ['height', 50],
        ]);

        $resource = new PlaceholderImageResource('#FFFFFF, #000000');
        $result = $resource->getFile('/test.png', 'fileadmin/test.png', $fileObject);

        self::assertIsString($result);
        self::assertNotEmpty($result);

        $imageInfo = getimagesizefromstring($result);
        self::assertIsArray($imageInfo);
        self::assertSame(100, $imageInfo[0]);
        self::assertSame(50, $imageInfo[1]);
    }

    #[Test]
    public function getFileReturnsFalseWithoutFileObject(): void
    {
        $resource = new PlaceholderImageResource('#CCCCCC, #969696');
        self::assertFalse($resource->getFile('/test.png', 'fileadmin/test.png'));
    }

    #[Test]
    public function constructorParsesStringConfiguration(): void
    {
        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn('svg');
        $fileObject->method('getProperty')->willReturnMap([
            ['width', 10],
            ['height', 10],
        ]);

        $resource = new PlaceholderImageResource('#FF0000, #00FF00');
        $result = $resource->getFile('/test.svg', 'fileadmin/test.svg', $fileObject);

        self::assertIsString($result);
        self::assertStringContainsString('#FF0000', $result);
        self::assertStringContainsString('#00FF00', $result);
    }

    #[Test]
    public function constructorParsesArrayConfiguration(): void
    {
        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn('svg');
        $fileObject->method('getProperty')->willReturnMap([
            ['width', 10],
            ['height', 10],
        ]);

        $resource = new PlaceholderImageResource([
            'backgroundColor' => '#AABBCC',
            'textColor' => '#112233',
        ]);
        $result = $resource->getFile('/test.svg', 'fileadmin/test.svg', $fileObject);

        self::assertIsString($result);
        self::assertStringContainsString('#AABBCC', $result);
        self::assertStringContainsString('#112233', $result);
    }

    #[Test]
    public function minimumDimensionsAreEnforced(): void
    {
        $fileObject = $this->createMock(FileInterface::class);
        $fileObject->method('getExtension')->willReturn('svg');
        $fileObject->method('getProperty')->willReturnMap([
            ['width', 0],
            ['height', -5],
        ]);

        $resource = new PlaceholderImageResource('#CCCCCC, #969696');
        $result = $resource->getFile('/test.svg', 'fileadmin/test.svg', $fileObject);

        self::assertIsString($result);
        self::assertStringContainsString('width="1"', $result);
        self::assertStringContainsString('height="1"', $result);
    }
}
