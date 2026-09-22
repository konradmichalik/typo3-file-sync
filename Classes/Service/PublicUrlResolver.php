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

use InvalidArgumentException;
use TYPO3\CMS\Core\Resource\{ResourceStorage, StorageRepository};

use function array_unique;
use function array_values;
use function is_string;
use function ltrim;
use function parse_url;
use function rawurldecode;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function usort;

/**
 * PublicUrlResolver.
 *
 * Turns the URLs a rendered page carries back into the storage-relative
 * identifiers FAL knows them by, for a given set of storages.
 *
 * It is its own class rather than part of the middleware that asks: reading
 * a storage's public prefix has nothing to do with rewriting markup, and the
 * middleware is the one file in this extension that every frontend response
 * of every installation passes through.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class PublicUrlResolver
{
    public function __construct(
        private StorageRepository $storageRepository,
    ) {}

    /**
     * @param list<string> $urls
     * @param list<int>    $storageUids
     *
     * @return array<string, string>
     */
    public function identifiersByUrl(array $urls, array $storageUids): array
    {
        $prefixes = $this->publicPrefixes($storageUids);
        $map = [];
        foreach ($urls as $url) {
            $identifier = self::toIdentifier($url, $prefixes);
            if (null !== $identifier) {
                $map[$url] = $identifier;
            }
        }

        return $map;
    }

    /**
     * A src is whatever the renderer produced: site-relative with or without
     * a leading slash depending on absRefPrefix, or absolute when the site
     * points its assets at another host. All three put the storage prefix at
     * the start of the path once the leading slash is normalised, so that is
     * where it is looked for first, longest prefix first.
     *
     * The unanchored search behind it is for the one shape the first pass
     * cannot reach: an absRefPrefix that carries a path of its own, so the
     * rendered src is /assets/fileadmin/... where the storage's own prefix is
     * /fileadmin/. It has to stay second, because a storage rooted at
     * /media/ that happens to contain a folder called fileadmin produces
     * /media/fileadmin/photo.jpg, and an unanchored match on /fileadmin/
     * turns that into /photo.jpg. That used to cost nothing, because the
     * consequence was an attribute on a tag and the lookup behind it was an
     * exact match that would simply miss. It is now also which stored preview
     * is inlined into src, and that one does not miss: it shows the visitor
     * another picture.
     *
     * @param list<string> $prefixes
     */
    private static function toIdentifier(string $url, array $prefixes): ?string
    {
        $path = parse_url($url, \PHP_URL_PATH);
        if (!is_string($path) || '' === $path) {
            return null;
        }

        $path = '/'.ltrim(rawurldecode($path), '/');
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return '/'.substr($path, strlen($prefix));
            }
        }

        foreach ($prefixes as $prefix) {
            $position = strpos($path, $prefix);
            if (false !== $position) {
                return '/'.substr($path, $position + strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @param list<int> $storageUids
     *
     * @return list<string>
     */
    private function publicPrefixes(array $storageUids): array
    {
        $prefixes = [];
        foreach ($storageUids as $storageUid) {
            $prefix = $this->publicPrefixOfStorage($storageUid);
            if (null !== $prefix) {
                $prefixes[] = $prefix;
            }
        }

        $prefixes = array_values(array_unique($prefixes));
        // A nested storage must win over the one it sits inside, otherwise
        // its files are resolved against the wrong root.
        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $prefixes;
    }

    private function publicPrefixOfStorage(int $storageUid): ?string
    {
        // Storage 0 is the fallback storage and is never a deferred one.
        if ($storageUid < 1) {
            return null;
        }

        try {
            return self::publicPrefix($this->storageRepository->getStorageObject($storageUid));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function publicPrefix(ResourceStorage $storage): ?string
    {
        // getRootLevelFolder(false) bypasses backend file mounts, which are
        // irrelevant to a frontend URL and would yield a subfolder.
        $publicUrl = $storage->getPublicUrl($storage->getRootLevelFolder(false));
        if (null === $publicUrl) {
            return null;
        }

        $path = parse_url($publicUrl, \PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        $path = trim(rawurldecode($path), '/');

        return '' === $path ? '/' : '/'.$path.'/';
    }
}
