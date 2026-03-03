<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_file_sync" TYPO3 CMS extension.
 *
 * (c) 2025 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource\Handler;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KonradMichalik\Typo3FileSync\Resource\Handler\RemoteInstanceResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\RequestFactory;

#[CoversClass(RemoteInstanceResource::class)]
final class RemoteInstanceResourceTest extends TestCase
{
    #[Test]
    public function hasFileReturnsTrueOnSuccessfulHeadRequest(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')
            ->with('https://example.com/fileadmin/test.jpg', 'HEAD')
            ->willReturn(new Response(200));

        $resource = new RemoteInstanceResource('https://example.com', $requestFactory);
        self::assertTrue($resource->hasFile('/test.jpg', 'fileadmin/test.jpg'));
    }

    #[Test]
    public function hasFileReturnsFalseOn404(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')
            ->willReturn(new Response(404));

        $resource = new RemoteInstanceResource('https://example.com', $requestFactory);
        self::assertFalse($resource->hasFile('/test.jpg', 'fileadmin/test.jpg'));
    }

    #[Test]
    public function hasFileReturnsFalseOnConnectionException(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')
            ->willThrowException(new ConnectException('Connection refused', new Request('HEAD', '')));

        $resource = new RemoteInstanceResource('https://example.com', $requestFactory);
        self::assertFalse($resource->hasFile('/test.jpg', 'fileadmin/test.jpg'));
    }

    #[Test]
    public function getFileReturnsBodyContent(): void
    {
        $body = 'file-content-binary-data';
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')
            ->with('https://example.com/fileadmin/test.jpg')
            ->willReturn(new Response(200, [], $body));

        $resource = new RemoteInstanceResource('https://example.com', $requestFactory);
        self::assertSame($body, $resource->getFile('/test.jpg', 'fileadmin/test.jpg'));
    }

    #[Test]
    public function getFileReturnsFalseOnException(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')
            ->willThrowException(new ConnectException('Connection refused', new Request('GET', '')));

        $resource = new RemoteInstanceResource('https://example.com', $requestFactory);
        self::assertFalse($resource->getFile('/test.jpg', 'fileadmin/test.jpg'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function urlNormalizationDataProvider(): array
    {
        return [
            'url with trailing slash' => ['https://example.com/', 'https://example.com/fileadmin/test.jpg'],
            'url without trailing slash' => ['https://example.com', 'https://example.com/fileadmin/test.jpg'],
            'url with path' => ['https://example.com/sub', 'https://example.com/sub/fileadmin/test.jpg'],
            'url with path and trailing slash' => ['https://example.com/sub/', 'https://example.com/sub/fileadmin/test.jpg'],
        ];
    }

    #[Test]
    #[DataProvider('urlNormalizationDataProvider')]
    public function urlIsNormalizedCorrectly(string $input, string $expectedUrl): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->expects(self::once())
            ->method('request')
            ->with($expectedUrl, 'HEAD')
            ->willReturn(new Response(200));

        $resource = new RemoteInstanceResource($input, $requestFactory);
        $resource->hasFile('/test.jpg', 'fileadmin/test.jpg');
    }

    #[Test]
    public function constructorAcceptsArrayConfiguration(): void
    {
        $requestFactory = $this->createMock(RequestFactory::class);
        $requestFactory->method('request')
            ->with('https://production.example.com/fileadmin/test.jpg', 'HEAD')
            ->willReturn(new Response(200));

        $resource = new RemoteInstanceResource(['url' => 'https://production.example.com'], $requestFactory);
        self::assertTrue($resource->hasFile('/test.jpg', 'fileadmin/test.jpg'));
    }
}
