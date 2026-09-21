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

use Generator;
use KonradMichalik\Typo3FileSync\Service\DeferredTokenService;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Crypto\HashService;


/**
 * DeferredTokenServiceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

#[CoversClass(DeferredTokenService::class)]
final class DeferredTokenServiceTest extends TestCase
{
    private DeferredTokenService $subject;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('a', 96);
        $this->subject = new DeferredTokenService(new HashService());
    }

    #[Test]
    public function createdTokenResolvesBackToTheSameUid(): void
    {
        self::assertSame(42, $this->subject->resolve($this->subject->create(42)));
    }

    #[Test]
    public function tokensForDifferentUidsDiffer(): void
    {
        self::assertNotSame($this->subject->create(1), $this->subject->create(2));
    }

    #[Test]
    public function tamperedUidIsRejected(): void
    {
        [, $signature] = explode('.', $this->subject->create(42), 2);

        self::assertNull($this->subject->resolve('43.'.$signature));
    }

    #[Test]
    #[DataProvider('malformedTokens')]
    public function malformedTokenIsRejected(string $token): void
    {
        self::assertNull($this->subject->resolve($token));
    }

    public static function malformedTokens(): Generator
    {
        yield 'empty' => [''];
        yield 'no separator' => ['42'];
        yield 'empty signature' => ['42.'];
        yield 'non numeric uid' => ['abc.deadbeef'];
        yield 'zero uid' => ['0.deadbeef'];
        yield 'negative uid' => ['-1.deadbeef'];
    }
}
