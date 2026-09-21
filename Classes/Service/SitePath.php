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

namespace KonradMichalik\Typo3FileSync\Service;

use TYPO3\CMS\Core\Utility\{GeneralUtility, PathUtility};

use function str_starts_with;
use function trim;

/**
 * SitePath.
 *
 * Everything the deferred loading feature hands to a browser has to be
 * rooted at the site, not at the page the browser happens to be on. The
 * materialize request is answered before site resolution, so neither
 * $GLOBALS['TSFE'] nor the PublicUrlPrefixer listener that normally adds
 * the leading slash exists on it, and FAL returns URLs such as
 * "fileadmin/x.jpg" that a browser resolves against the current directory.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SitePath
{
    /**
     * "/" for a site at the document root, "/sub/dir/" below one. Read from
     * the same source PathUtility::getAbsoluteWebPath() consults, so the
     * swapped image URL, the injected module tag and the endpoint the module
     * posts to cannot disagree about the prefix.
     */
    public static function prefix(): string
    {
        $path = trim((string) GeneralUtility::getIndpEnv('TYPO3_SITE_PATH'), '/');

        return '' === $path ? '/' : '/'.$path.'/';
    }

    /**
     * Roots a URL that is neither absolute nor already rooted. A URL with a
     * scheme was produced for another host, typically a CDN base URL, and is
     * none of this extension's business.
     */
    public static function absolute(string $url): string
    {
        if (PathUtility::hasProtocolAndScheme($url) || str_starts_with($url, '/')) {
            return $url;
        }

        return self::prefix().$url;
    }
}
