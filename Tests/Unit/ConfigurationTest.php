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

namespace KonradMichalik\Typo3FileSync\Tests\Unit;

use KonradMichalik\Typo3FileSync\Configuration;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;


/**
 * ConfigurationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    #[Test]
    public function extKeyIsCorrect(): void
    {
        self::assertSame('typo3_file_sync', Configuration::EXT_KEY);
    }

    #[Test]
    public function extNameIsCorrect(): void
    {
        self::assertSame('Typo3FileSync', Configuration::EXT_NAME);
    }
}
