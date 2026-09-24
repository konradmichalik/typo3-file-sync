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

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\{Directive, Mutation, MutationCollection, MutationMode, Scope, SourceKeyword};
use TYPO3\CMS\Core\Type\Map;

// Literal feature toggle key, not Configuration::FEATURE_DEFERRED_LOADING: this file is required
// by AbstractServiceProvider while the DI container compiles, before the extension's own
// autoloader is guaranteed to be primed, the same reasoning as in TCA/Overrides/sys_file_storage.php.
if (($GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['fileSync.deferredLoading'] ?? false) !== true) {
    return new Map();
}

// The deferred-image swap module (DeferredImageMiddleware::snippet()) is injected as an external
// <script> tag from this extension's own Resources/Public, always same-origin, but carries no
// nonce. TYPO3's default frontend policy restricts script-src to a nonce proxy, which would
// silently block the swap on any site enforcing it. 'self' is the narrowest source that works
// regardless of hostname or installation layout (classic vs Composer, single vs multi-domain
// site): CSP host-source grammar requires a host part, so a path scoped to this asset's own
// public URL is not an option, that URL differs per installation and carries a content hash
// under Composer mode.
return Map::fromEntries([
    Scope::frontend(),
    new MutationCollection(
        new Mutation(MutationMode::Extend, Directive::ScriptSrc, SourceKeyword::self),
    ),
]);
