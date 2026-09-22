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

namespace KonradMichalik\Typo3FileSync\Tests\Functional\Service;

use KonradMichalik\Typo3FileSync\Service\PublicUrlResolver;
use PHPUnit\Framework\Attributes\{CoversClass, DataProvider, Test};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * PublicUrlResolverTest.
 *
 * The storages are real local ones, so the prefixes under test are the ones
 * FAL would really have produced rather than ones the test made up.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PublicUrlResolver::class)]
final class PublicUrlResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3_file_sync'];

    private PublicUrlResolver $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // The nested storage cannot be initialised before its root exists.
        GeneralUtility::mkdir_deep($this->instancePath.'/fileadmin/nested/');
        $this->importCSVDataSet(__DIR__.'/Fixtures/public_url_storages.csv');
        $this->subject = $this->get(PublicUrlResolver::class);
    }

    /**
     * The three shapes a renderer produces, depending on absRefPrefix and on
     * whether the site serves its assets from another host.
     *
     * @return array<string, list<string>>
     */
    public static function urlShapeProvider(): array
    {
        return [
            'site relative' => ['/fileadmin/_processed_/a/b/csm_aaa.jpg'],
            'without a leading slash' => ['fileadmin/_processed_/a/b/csm_aaa.jpg'],
            'absolute on another host' => ['https://assets.example.com/fileadmin/_processed_/a/b/csm_aaa.jpg'],
            'with a cache buster' => ['/fileadmin/_processed_/a/b/csm_aaa.jpg?v=17'],
            'url encoded' => ['/fileadmin/_processed_/a/b/csm%5Faaa.jpg'],
        ];
    }

    #[Test]
    #[DataProvider('urlShapeProvider')]
    public function stripsThePublicPrefixOffEveryUrlShapeARendererProduces(string $url): void
    {
        $result = $this->subject->identifiersByUrl([$url], [9]);

        self::assertSame([$url => '/_processed_/a/b/csm_aaa.jpg'], $result);
    }

    /**
     * A nested storage must win over the one it sits inside: resolving its
     * files against the outer root would yield an identifier no row carries.
     */
    #[Test]
    public function resolvesAUrlAgainstTheInnermostStorageItSitsIn(): void
    {
        $result = $this->subject->identifiersByUrl(['/fileadmin/nested/_processed_/csm_bbb.jpg'], [9, 10]);

        self::assertSame(['/fileadmin/nested/_processed_/csm_bbb.jpg' => '/_processed_/csm_bbb.jpg'], $result);
    }

    #[Test]
    public function dropsAUrlThatBelongsToNoneOfTheGivenStorages(): void
    {
        $result = $this->subject->identifiersByUrl([
            '/typo3temp/assets/images/csm_ccc.jpg',
            '/fileadmin/_processed_/a/b/csm_aaa.jpg',
        ], [9]);

        self::assertSame(['/fileadmin/_processed_/a/b/csm_aaa.jpg' => '/_processed_/a/b/csm_aaa.jpg'], $result);
    }

    #[Test]
    public function dropsAUrlWithoutAPath(): void
    {
        self::assertSame([], $this->subject->identifiersByUrl(['#anchor-only'], [9]));
    }

    /**
     * Storage 0 is the fallback storage, which has no public root to strip,
     * and a uid no storage carries must not take the lookup down with it.
     */
    #[Test]
    public function ignoresAStorageUidThatResolvesToNothing(): void
    {
        $result = $this->subject->identifiersByUrl(['/fileadmin/_processed_/a/b/csm_aaa.jpg'], [0, 4711, 9]);

        self::assertSame(['/fileadmin/_processed_/a/b/csm_aaa.jpg' => '/_processed_/a/b/csm_aaa.jpg'], $result);
    }
}
