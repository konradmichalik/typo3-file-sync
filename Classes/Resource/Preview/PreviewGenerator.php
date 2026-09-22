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

namespace KonradMichalik\Typo3FileSync\Resource\Preview;

use GdImage;
use Psr\Log\{LoggerAwareInterface, LoggerAwareTrait};

use function function_exists;
use function sprintf;
use function strlen;

/**
 * PreviewGenerator.
 *
 * Decodes the bytes of a remote HTTP response and produces a tiny blurred
 * WebP preview. The input is whatever a remote instance sent back, so every
 * guard here defends against that payload rather than against a local
 * record: a byte cap before decoding, getimagesizefromstring() to confirm
 * it is an image at all, and caps on both the edges and the pixel count
 * before allocating a GD canvas.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PreviewGenerator implements LoggerAwareInterface
{
    // Not readonly as a class any more: the injected logger is mutable state
    // by the interface's own contract. Everything this class decides with is
    // still readonly.
    use LoggerAwareTrait;

    /**
     * The largest payload this class will decode.
     *
     * Public because the reader has to know it before it buffers anything:
     * a cap of its own would be a second number for the same quantity, and
     * the two would drift into a reader that holds bytes this class then
     * rejects, or one that drops bytes it would have taken.
     */
    public const MAX_BYTES = 2_097_152;
    private const MAX_SOURCE_DIMENSION = 4096;

    /**
     * Four megapixels, which is the square 2048x2048.
     *
     * The byte cap and the per-edge cap do not compose without it: a
     * solid-colour 4096x4096 PNG is a few tens of kilobytes, so it passes
     * the first, and its edges sit exactly on the second. libgd then
     * allocates that image's raw pixel buffer, 64 MiB of it, outside PHP's
     * own allocator, where memory_limit cannot see it. The pixel count is
     * the quantity that actually bounds the allocation, and four megapixels
     * is already two orders of magnitude past what a 32 pixel preview can
     * use.
     */
    private const MAX_SOURCE_PIXELS = 4_194_304;
    private const EDGE = 32;
    private const QUALITY = 60;
    private const BLUR_PASSES = 2;

    /**
     * @param bool|null $webpSupported what this build can encode, asked of GD
     *                                 when null, which is what Services.yaml passes. It is a
     *                                 parameter because a build without the encoder cannot be
     *                                 reproduced in process: function_exists() is imported here, so
     *                                 no namespace-local stub reaches it
     */
    public function __construct(private readonly ?bool $webpSupported) {}

    /**
     * Whether a preview can be produced at all on this build. A GD compiled
     * without WebP support has no imagewebp(), and calling it would be an
     * uncaught Error on a request whose whole contract is that it degrades
     * to the grey placeholder.
     *
     * Public because the caller has to ask before it fetches: the source
     * rendition is downloaded from the remote instance, and downloading it
     * to feed an encoder that does not exist is traffic paid for nothing.
     */
    public function isSupported(): bool
    {
        return $this->webpSupported ?? function_exists('imagewebp');
    }

    public function generate(string $bytes, int $targetWidth, int $targetHeight): ?string
    {
        // Checked before anything is allocated, so there is no buffer and no
        // canvas to unwind. Callers are expected to ask isSupported() long
        // before this, but this method is the one that would fatal.
        if (!$this->isSupported()) {
            return null;
        }

        if ('' === $bytes || $targetWidth < 1 || $targetHeight < 1) {
            return null;
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            $this->rejectedForSize(sprintf('%d bytes, over the %d byte cap', strlen($bytes), self::MAX_BYTES));

            return null;
        }

        $source = $this->acceptableSource($bytes);
        if (null === $source) {
            return null;
        }

        [$sourceWidth, $sourceHeight] = $source;
        $canvas = $this->decodeQuietly(static fn (): GdImage|false => imagecreatefromstring($bytes));
        if (false === $canvas) {
            return null;
        }

        [$width, $height] = $this->scaleToEdge($targetWidth, $targetHeight);
        $target = imagecreatetruecolor($width, $height);
        [$cropX, $cropY, $cropWidth, $cropHeight] = $this->crop($sourceWidth, $sourceHeight, $targetWidth / $targetHeight);
        imagecopyresampled($target, $canvas, 0, 0, $cropX, $cropY, $width, $height, $cropWidth, $cropHeight);

        for ($pass = 0; $pass < self::BLUR_PASSES; ++$pass) {
            imagefilter($target, \IMG_FILTER_GAUSSIAN_BLUR);
        }

        // finally, not a plain pair: imagewebp() writing to the output buffer
        // is the one call here that can still fail on an encoder this build
        // does have, and an exception escaping between ob_start() and
        // ob_get_clean() would leave the buffer open for the rest of the
        // request, swallowing whatever the response was supposed to be.
        ob_start();

        try {
            imagewebp($target, null, self::QUALITY);
        } finally {
            $webp = ob_get_clean();
        }

        // imagedestroy() is a no-op since PHP 8.0 and deprecated since 8.5;
        // GD images are garbage-collected like any other object.
        return $webp ?: null;
    }

    /**
     * The source's own dimensions, or null when the payload is not an image
     * this class is willing to decode.
     *
     * @return array{int<1, max>, int<1, max>}|null
     */
    private function acceptableSource(string $bytes): ?array
    {
        $info = $this->decodeQuietly(static fn (): array|false => getimagesizefromstring($bytes));
        if (false === $info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        if ($info[0] > self::MAX_SOURCE_DIMENSION || $info[1] > self::MAX_SOURCE_DIMENSION
            || $info[0] * $info[1] > self::MAX_SOURCE_PIXELS
        ) {
            $this->rejectedForSize(sprintf(
                '%dx%d pixels, over the %d pixel cap or the %d pixel edge',
                $info[0],
                $info[1],
                self::MAX_SOURCE_PIXELS,
                self::MAX_SOURCE_DIMENSION,
            ));

            return null;
        }

        return [$info[0], $info[1]];
    }

    /**
     * The one rejection an operator has to be able to tell apart from the
     * others. A source that is simply too big is not a dead remote and not a
     * failing encoder: the rendition is damped, fetched again once the window
     * passes and thrown away again, for as long as that source stays that
     * size. Every other rejection here is a payload that is not an image,
     * which needs no line of its own because the remote answering with
     * something else is already visible in what it served.
     */
    private function rejectedForSize(string $reason): void
    {
        $this->logger?->warning(sprintf('A preview source was rejected: %s.', $reason));
    }

    /**
     * Runs a GD decode call with warnings suppressed. The "@" operator is
     * disallowed project-wide, but a malformed payload from a remote server
     * triggering a decode warning is expected input here, not a bug to log.
     *
     * @template T
     *
     * @param callable(): T $decode
     *
     * @return T
     */
    private function decodeQuietly(callable $decode): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $decode();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{int<1, max>, int<1, max>}
     */
    private function scaleToEdge(int $width, int $height): array
    {
        $scale = self::EDGE / max($width, $height);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /**
     * Crops the source to the target aspect ratio, so a square rendition slot
     * does not stretch a landscape preview across its box.
     *
     * A tiny source combined with an extreme target ratio can round the
     * computed edge down to 0, which imagecopyresampled() accepts silently
     * and turns into a black preview instead of a rejection. The floor at 1
     * mirrors scaleToEdge()'s own floor and keeps the crop inside the
     * source, since sourceWidth/sourceHeight are both already known to be
     * at least 1 by the time this runs.
     *
     * @param int<1, max> $sourceWidth
     * @param int<1, max> $sourceHeight
     *
     * @return array{int, int, int<1, max>, int<1, max>}
     */
    private function crop(int $sourceWidth, int $sourceHeight, float $targetRatio): array
    {
        $sourceRatio = $sourceWidth / $sourceHeight;
        if ($sourceRatio > $targetRatio) {
            $cropWidth = max(1, (int) round($sourceHeight * $targetRatio));

            return [(int) round(($sourceWidth - $cropWidth) / 2), 0, $cropWidth, $sourceHeight];
        }

        $cropHeight = max(1, (int) round($sourceWidth / $targetRatio));

        return [0, (int) round(($sourceHeight - $cropHeight) / 2), $sourceWidth, $cropHeight];
    }
}
