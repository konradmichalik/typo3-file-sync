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

use KonradMichalik\Typo3FileSync\Repository\FileRepository;
use KonradMichalik\Typo3FileSync\Resource\Preview\{PreviewGenerator, PreviewSourceReader, PreviewStore};
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};
use Throwable;

use function array_fill_keys;
use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function base64_encode;
use function count;
use function sprintf;
use function time;

/**
 * PreviewService.
 *
 * The second stage of the materialize endpoint: instead of the real file it
 * answers with a tiny blurred WebP inline as a data URI, so the grey
 * placeholder on the page turns into a recognisable version of the picture
 * while the real bytes are still on their way.
 *
 * Two things make that affordable on demand. It downloads the smallest
 * rendition production already has, which is kilobytes where the original is
 * megabytes, and it stores what it built, so nothing is downloaded twice
 * inside one batch and a page that has been visited before costs nothing.
 *
 * What that costs, for anyone sizing the traffic: every rendition of one
 * picture resolves to the same source, so a batch downloads it once however
 * many renditions ask for it. A preview is stored per rendition rather than
 * per picture, though, because the crop follows the shape of the rendition
 * the browser is waiting for. A rendition whose own preview is not stored yet
 * therefore re-fetches a source a sibling already used, which puts the bound
 * at one fetch per distinct rendition over the store's lifetime rather than
 * one per picture.
 *
 * Nothing here throws. A preview that cannot be produced leaves the visitor
 * with the grey placeholder that is already on screen, and one unusable
 * token must not cost the rest of the batch its previews.
 *
 * @phpstan-type PreviewLocation array{storage: int, identifier: string}
 * @phpstan-type PreviewPlan array{requested: PreviewLocation, source: array{identifier: string, storage: int, width: int, height: int}, width: int, height: int}
 * @phpstan-type PreviewResult array{preview: string}|array{error: string}
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PreviewService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * How long a rendition the remote could not deliver stays unasked.
     *
     * Deliberately the same 300 seconds MaterializationService damps an
     * original for, although a preview source is kilobytes where an original
     * is megabytes. The cheaper fetch is not the quantity that matters here:
     * the rate limiter admits 60 requests a minute of 50 tokens each, so a
     * page whose renditions no longer exist upstream drives thousands of
     * fetches a minute per visitor, which is the more failing requests, not
     * the fewer. Cheaper each, fifty times as many, so the same window. And
     * one number for both stages of one endpoint is one number to reason
     * about rather than two to explain.
     */
    private const DAMPING_SECONDS = 300;

    public function __construct(
        private readonly DeferredTokenService $deferredTokenService,
        private readonly FileRepository $fileRepository,
        private readonly PreviewGenerator $previewGenerator,
        private readonly PreviewSourceReader $previewSourceReader,
        private readonly PreviewStore $previewStore,
    ) {}

    /**
     * @param list<string> $tokens
     *
     * @return array<string, PreviewResult>
     */
    public function preview(array $tokens): array
    {
        // The endpoint's own limit rather than a second copy of it: this
        // service is reachable from the container like any other, and two
        // definitions of "a page's worth of images" would drift.
        if (count($tokens) > MaterializationService::MAX_TOKENS) {
            return [];
        }

        // Ahead of every lookup and every fetch. On a GD build without a WebP
        // encoder no preview is ever produced and therefore none is ever
        // stored, so each rendition is marked again on every response and
        // asked for again on every page view. Letting that reach the source
        // reader would buy a real download from the remote instance per ask,
        // for bytes thrown away on the next line. Damping bounds how often
        // that happens, never how long it goes on for.
        if (!$this->previewGenerator->isSupported()) {
            return array_fill_keys($tokens, ['error' => 'unavailable']);
        }

        $results = [];
        $uidsByToken = [];
        foreach ($tokens as $token) {
            $uid = $this->deferredTokenService->resolve($token);
            if (null === $uid) {
                $results[$token] = ['error' => 'invalid'];
                continue;
            }
            $uidsByToken[$token] = $uid;
        }

        [$plans, $answered] = $this->plan($uidsByToken);

        return $results + $answered + $this->renderAll($plans);
    }

    /**
     * Everything that can be decided without touching the network: which
     * tokens name a rendition that exists, which of those already have a
     * stored preview, and which of the rest have a smaller rendition to build
     * one from.
     *
     * @param array<string, int> $uidsByToken
     *
     * @return array{array<string, PreviewPlan>, array<string, PreviewResult>}
     */
    private function plan(array $uidsByToken): array
    {
        $processedRows = $this->fileRepository->findProcessedFilesByUids(array_values($uidsByToken));

        // One query for the batch rather than one per token. The rendition
        // lookup is uncapped by design, and this method already holds every
        // row it would be asked about.
        $sources = $this->fileRepository->findSmallestRenditions(array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['original'],
            $processedRows,
        ))));

        $plans = [];
        $results = [];
        foreach ($uidsByToken as $token => $uid) {
            $outcome = $this->decide($processedRows[$uid] ?? null, $sources);
            if (array_key_exists('plan', $outcome)) {
                $plans[$token] = $outcome['plan'];
                continue;
            }

            $results[$token] = $outcome['result'];
        }

        return [$plans, $results];
    }

    /**
     * What a single token resolves to before anything is fetched: a plan to
     * build a preview, or the answer it already has.
     *
     * @param array<string, mixed>|null                                                    $row
     * @param array<int, array{identifier: string, storage: int, width: int, height: int}> $sources the batch's resolved preview sources, keyed by original uid
     *
     * @return array{plan: PreviewPlan}|array{result: PreviewResult}
     */
    private function decide(?array $row, array $sources): array
    {
        if (null === $row) {
            return ['result' => ['error' => 'invalid']];
        }

        $requested = self::locate($row);
        if (null === $requested) {
            return ['result' => ['error' => 'unavailable']];
        }

        // Ahead of the rendition lookup, let alone any request: a stored
        // preview is what makes every visitor after the first one free.
        // Unguarded for the same reason as isDamped() below.
        $stored = $this->previewStore->read($requested['storage'], $requested['identifier']);
        if (null !== $stored) {
            return ['result' => self::dataUri($stored)];
        }

        // A rendition that failed recently is answered without asking again.
        // A failure is never stored as a preview, so without this every
        // visitor of the same page retries the same dead fetch.
        if ($this->isDamped($requested)) {
            return ['result' => ['error' => 'unavailable']];
        }

        $source = $sources[(int) $row['original']] ?? null;
        if (null === $source || self::isRequestedItself($source, $requested)) {
            return ['result' => ['error' => 'unavailable']];
        }

        return ['plan' => [
            'requested' => $requested,
            'source' => $source,
            'width' => (int) $row['width'],
            'height' => (int) $row['height'],
        ]];
    }

    /**
     * Whether the only source on offer is the rendition the browser is
     * already waiting for. Blurring that one downloads the very file the
     * other stage is about to deliver, and buys nothing but a couple of
     * hundred bytes of blur for the seconds in between. A sibling rendition
     * was chosen as the narrowest there is, so it stays a cheap proxy and
     * needs no threshold of its own.
     *
     * @param array{identifier: string, storage: int, width: int, height: int} $source
     * @param PreviewLocation                                                  $requested
     */
    private static function isRequestedItself(array $source, array $requested): bool
    {
        return $source['storage'] === $requested['storage']
            && $source['identifier'] === $requested['identifier'];
    }

    /**
     * The rendition a token names, which is both the shape a preview is
     * cropped to and the key it is stored under.
     *
     * A processed row carries an empty identifier until its file has actually
     * been written. There is nothing to key a preview by then, and storing one
     * under the empty string would hand every such rendition of the storage
     * the first one's picture.
     *
     * @param array<string, mixed> $row
     *
     * @return PreviewLocation|null
     */
    private static function locate(array $row): ?array
    {
        $identifier = (string) $row['identifier'];

        return '' === $identifier ? null : ['storage' => (int) $row['storage'], 'identifier' => $identifier];
    }

    /**
     * Whether this rendition failed inside the damping window. The marker
     * holds nothing but the second it was written in, so a stale one expires
     * by being read rather than by being swept.
     *
     * @param PreviewLocation $requested
     */
    private function isDamped(array $requested): bool
    {
        // readMarker() rather than read(), which would apply the store's WebP
        // check to something that holds a timestamp. Unguarded because the
        // store only reads here: is_file() plus file_get_contents(), whose
        // failure is an E_WARNING, and TYPO3's default exceptionalErrors
        // excludes E_WARNING, so there is no Throwable to catch.
        $marker = self::damped($requested);
        $marked = $this->previewStore->readMarker($marker['storage'], $marker['identifier']);

        return null !== $marked && time() - (int) $marked < self::DAMPING_SECONDS;
    }

    /**
     * Arms the damping window, so no caller can answer 'unavailable' after a
     * failed fetch without also blocking the retry.
     *
     * @param PreviewLocation $requested
     *
     * @return array{error: string}
     */
    private function damp(array $requested): array
    {
        $this->amend(self::damped($requested), (string) time());

        return ['error' => 'unavailable'];
    }

    /**
     * The key a failure is remembered under. It cannot collide with a
     * preview's own key, because a FAL identifier always starts with a slash.
     *
     * @param PreviewLocation $requested
     *
     * @return PreviewLocation
     */
    private static function damped(array $requested): array
    {
        return ['storage' => $requested['storage'], 'identifier' => 'failed:'.$requested['identifier']];
    }

    /**
     * @param array<string, PreviewPlan> $plans
     *
     * @return array<string, PreviewResult>
     */
    private function renderAll(array $plans): array
    {
        if ([] === $plans) {
            return [];
        }

        $bytes = $this->previewSourceReader->read(array_map(
            static fn (array $plan): array => ['storage' => $plan['source']['storage'], 'identifier' => $plan['source']['identifier']],
            $plans,
        ));

        $results = [];
        foreach ($plans as $token => $plan) {
            $results[$token] = $this->render($plan, $bytes[$token] ?? null);
        }

        return $results;
    }

    /**
     * The generator guards the payload itself and answers null for anything
     * it cannot use, but it drives a GD build this code does not control.
     * Wrapped like every other outside call on this path, because one image
     * that makes an encoder fail must cost its own token, not the batch.
     *
     * @param PreviewPlan $plan
     */
    private function encode(string $bytes, array $plan): ?string
    {
        try {
            return $this->previewGenerator->generate($bytes, $plan['width'], $plan['height']);
        } catch (Throwable $exception) {
            $this->logger?->warning(
                sprintf('Generating the preview of %s failed: %s', $plan['requested']['identifier'], $exception->getMessage()),
            );

            return null;
        }
    }

    /**
     * @param PreviewPlan $plan
     *
     * @return PreviewResult
     */
    private function render(array $plan, ?string $bytes): array
    {
        $webp = null === $bytes ? null : $this->encode($bytes, $plan);
        if (null === $webp) {
            return $this->damp($plan['requested']);
        }

        // Keyed by the rendition the browser is waiting for, not by the
        // original: the crop follows that rendition's aspect ratio, so one
        // preview per picture would stretch a landscape blur into the next
        // rendition's square slot. The extra cost is files, not fetches,
        // since every rendition of one picture resolves to the same source
        // and that source is downloaded once per batch.
        $this->amend($plan['requested'], $webp);

        // This rendition works again, so its failure marker would only make
        // the store grow without ever being read.
        $this->amend(self::damped($plan['requested']), null);

        return self::dataUri($webp);
    }

    /**
     * Every write this class makes to the store, guarded once. Writing a
     * preview, writing a damping marker and dropping a marker are the three,
     * and the store reports all three by throwing. A null payload means the
     * key is to go rather than to be written.
     *
     * Survivable in every case: an unwritable var/ costs the next visitor the
     * same fetch, and must not cost this one the preview already in hand.
     *
     * @param PreviewLocation $location
     */
    private function amend(array $location, ?string $contents): void
    {
        try {
            if (null === $contents) {
                $this->previewStore->remove($location['storage'], $location['identifier']);
            } else {
                $this->previewStore->write($location['storage'], $location['identifier'], $contents);
            }
        } catch (Throwable $exception) {
            $this->logger?->warning(sprintf(
                '%s %s failed: %s',
                null === $contents ? 'Dropping' : 'Storing',
                $location['identifier'],
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return array{preview: string}
     */
    private static function dataUri(string $webp): array
    {
        return ['preview' => 'data:image/webp;base64,'.base64_encode($webp)];
    }
}
