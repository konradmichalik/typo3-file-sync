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

use KonradMichalik\Ttt\Attribute\WithTypo3ConfVars;
use KonradMichalik\Typo3FileSync\Configuration;
use KonradMichalik\Typo3FileSync\Resource\Handler\{PlaceholderImageResource, RemoteInstanceResource};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/**
 * ConfigurationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
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

    #[Test]
    public function featureToggleNamesAreNamespaced(): void
    {
        self::assertSame('fileSync.deferredLoading', Configuration::FEATURE_DEFERRED_LOADING);
        self::assertSame('fileSync.previewImages', Configuration::FEATURE_PREVIEW_IMAGES);
    }

    #[Test]
    public function deferredFieldFollowsTheExtensionFieldPrefix(): void
    {
        self::assertSame('tx_typo3_file_sync_deferred', Configuration::FIELD_DEFERRED);
    }

    #[Test]
    public function extconfDeferredStoragesKeyIsCorrect(): void
    {
        self::assertSame('deferredStorages', Configuration::EXTCONF_DEFERRED_STORAGES);
    }

    #[Test]
    #[WithTypo3ConfVars(['EXTCONF' => []])]
    public function getResourceHandlersProvidesDefaultsWithoutExtconf(): void
    {
        $handlers = Configuration::getResourceHandlers();

        self::assertSame(['remote_instance', 'placeholder_image'], array_keys($handlers));
        self::assertSame(RemoteInstanceResource::class, $handlers['remote_instance']['handler']);
        self::assertSame(PlaceholderImageResource::class, $handlers['placeholder_image']['handler']);
    }

    #[Test]
    #[WithTypo3ConfVars(['EXTCONF' => ['typo3_file_sync' => ['resourceHandler' => [
        'custom' => ['title' => 'Custom', 'handler' => 'CustomHandler'],
        'remote_instance' => ['title' => 'Overridden', 'handler' => 'OtherHandler'],
    ]]]])]
    public function getResourceHandlersMergesExtconfOverDefaults(): void
    {
        $handlers = Configuration::getResourceHandlers();

        self::assertSame('CustomHandler', $handlers['custom']['handler']);
        self::assertSame('OtherHandler', $handlers['remote_instance']['handler']);
        self::assertArrayHasKey('placeholder_image', $handlers);
    }
}
