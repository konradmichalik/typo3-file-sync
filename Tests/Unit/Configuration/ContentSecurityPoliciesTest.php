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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Configuration;

use KonradMichalik\Typo3FileSync\Configuration;
use PHPUnit\Framework\Attributes\{CoversNothing, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\{Directive, MutationCollection, MutationMode, Scope, SourceKeyword};
use TYPO3\CMS\Core\Type\Map;

/**
 * ContentSecurityPoliciesTest.
 *
 * The file under test is required directly, with `TYPO3_CONF_VARS` toggled
 * beforehand, the same way `AbstractServiceProvider::configureContentSecurityPolicies()`
 * requires it while compiling the DI container.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversNothing]
final class ContentSecurityPoliciesTest extends TestCase
{
    private const FILE = __DIR__.'/../../../Configuration/ContentSecurityPolicies.php';

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING]);
    }

    #[Test]
    public function extendsScriptSrcWithSelfWhileDeferredLoadingIsEnabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = true;

        $mutations = require self::FILE;
        self::assertInstanceOf(Map::class, $mutations);
        $collection = $mutations[Scope::frontend()];
        self::assertInstanceOf(MutationCollection::class, $collection);

        self::assertCount(1, $collection->mutations);
        $mutation = $collection->mutations[0];
        self::assertSame(MutationMode::Extend, $mutation->mode);
        self::assertSame(Directive::ScriptSrc, $mutation->directive);
        self::assertSame([SourceKeyword::self], $mutation->sources);
    }

    #[Test]
    public function declaresNoMutationsWhileDeferredLoadingIsDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features'][Configuration::FEATURE_DEFERRED_LOADING] = false;

        $mutations = require self::FILE;
        self::assertInstanceOf(Map::class, $mutations);

        self::assertCount(0, $mutations);
    }
}
