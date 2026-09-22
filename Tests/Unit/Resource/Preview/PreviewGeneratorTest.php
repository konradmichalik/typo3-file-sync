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

namespace KonradMichalik\Typo3FileSync\Tests\Unit\Resource\Preview;

use KonradMichalik\Typo3FileSync\Resource\Preview\PreviewGenerator;
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * PreviewGeneratorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[CoversClass(PreviewGenerator::class)]
final class PreviewGeneratorTest extends TestCase
{
    private PreviewGenerator $subject;

    protected function setUp(): void
    {
        $this->subject = new PreviewGenerator(null);
    }

    #[Test]
    public function generatesAWebPWhoseLongestEdgeIsThirtyTwoPixels(): void
    {
        $result = $this->subject->generate($this->jpeg(400, 300), 400, 300);

        self::assertNotNull($result);
        $info = getimagesizefromstring($result);
        self::assertIsArray($info);
        [$width, $height] = $info;
        self::assertSame(32, max($width, $height));
    }

    #[Test]
    public function outputMatchesTheTargetAspectRatioRatherThanTheSourceOne(): void
    {
        $result = $this->subject->generate($this->jpeg(400, 100), 200, 200);

        self::assertNotNull($result);
        $info = getimagesizefromstring($result);
        self::assertIsArray($info);
        [$width, $height] = $info;
        self::assertSame($width, $height);
    }

    /**
     * The output canvas dimensions alone are always square here, because
     * they are derived from the target ratio regardless of any cropping.
     * This test instead inspects pixel content: a wide source is painted
     * red except for a centered green stripe exactly as wide as the crop()
     * method should select for a square target. If the crop were dropped
     * and the whole source stretched into the square canvas instead, the
     * red edges would bleed into the output; a correct crop keeps the
     * result solidly green all the way to its own edges.
     */
    #[Test]
    public function squareTargetCropsTheWideSourceInsteadOfStretchingIt(): void
    {
        $result = $this->subject->generate($this->sourceWithCenteredGreenStripe(400, 100), 200, 200);

        self::assertNotNull($result);
        $image = imagecreatefromstring($result);
        self::assertNotFalse($image);

        $height = imagesy($image);
        $edgePixel = imagecolorat($image, 0, (int) ($height / 2));
        $red = ($edgePixel >> 16) & 0xFF;
        $green = ($edgePixel >> 8) & 0xFF;

        self::assertGreaterThan($red, $green, 'Edge pixel should stay green: the red source edges must be cropped away, not stretched in.');
    }

    /**
     * A 1x1 source combined with a 0.3 target ratio drives crop() into
     * cropWidth = round(1 * 0.3) = 0: a degenerate, zero-width source
     * rectangle. imagecopyresampled() accepts a zero width silently and
     * leaves the destination at its initialised black, so a missing floor
     * here would produce a structurally valid but content-free preview
     * instead of a rejection or a real thumbnail. Asserting non-null is
     * not enough, since the unfixed method already returns non-null; the
     * pixel itself has to reflect the source colour, not stay black.
     */
    #[Test]
    public function degenerateCropFromATinySourceStillReflectsTheSourceColour(): void
    {
        $result = $this->subject->generate($this->jpeg(1, 1), 3, 10);

        self::assertNotNull($result);
        $image = imagecreatefromstring($result);
        self::assertNotFalse($image);

        $pixel = imagecolorat($image, 0, 0);
        $red = ($pixel >> 16) & 0xFF;

        self::assertGreaterThan(100, $red, 'Pixel should reflect the source colour (red channel 200): a black pixel means the crop rectangle collapsed to zero width instead of being floored.');
    }

    #[Test]
    public function outputIsWebP(): void
    {
        $result = $this->subject->generate($this->jpeg(400, 300), 400, 300);

        $info = getimagesizefromstring((string) $result);
        self::assertIsArray($info);
        self::assertSame(\IMAGETYPE_WEBP, $info[2]);
    }

    #[Test]
    public function outputStaysWellUnderOneKilobyte(): void
    {
        self::assertLessThan(1024, strlen((string) $this->subject->generate($this->jpeg(1600, 1200), 1600, 1200)));
    }

    /**
     * A GD build without a WebP encoder is what the constructor argument
     * stands in for: imagewebp() is absent there, so this build cannot be
     * made to reproduce it. The generator answers null rather than reaching
     * a call that would be a fatal error, and callers are expected to have
     * asked isSupported() before spending a download on the source.
     */
    #[Test]
    public function aBuildWithoutAWebPEncoderProducesNothing(): void
    {
        $subject = new PreviewGenerator(false);

        self::assertFalse($subject->isSupported());
        self::assertNull($subject->generate($this->jpeg(400, 300), 400, 300));
    }

    #[Test]
    public function nonImagePayloadIsRejected(): void
    {
        self::assertNull($this->subject->generate('<html>not an image</html>', 400, 300));
    }

    #[Test]
    public function emptyPayloadIsRejected(): void
    {
        self::assertNull($this->subject->generate('', 400, 300));
    }

    #[Test]
    public function garbagePayloadOfAnySizeIsRejected(): void
    {
        self::assertNull($this->subject->generate(str_repeat('A', 2_097_153), 400, 300));
    }

    /**
     * The garbage payload above is also rejected by the image-validity
     * check, so it cannot prove the byte cap runs before decoding on its
     * own. This payload is a structurally valid JPEG padded past the
     * cap: getimagesizefromstring() parses its intact header and
     * imagecreatefromstring() ignores the trailing padding, so both
     * decode guards would accept it. Only the byte cap, checked before
     * either call, can reject it.
     */
    #[Test]
    public function oversizedPayloadIsRejectedBeforeDecoding(): void
    {
        self::assertNull($this->subject->generate($this->oversizedButValidJpeg(), 400, 300));
    }

    /**
     * The WebP is encoded into an output buffer. An encoder that fails between
     * ob_start() and ob_get_clean() would leave that buffer open for the rest
     * of the request, swallowing whatever response follows it, so the buffer
     * has to be closed on every exit rather than only the happy one.
     */
    #[Test]
    public function theOutputBufferIsBalancedAcrossAGenerate(): void
    {
        $level = ob_get_level();

        $this->subject->generate($this->jpeg(400, 300), 400, 300);

        self::assertSame($level, ob_get_level());
    }

    #[Test]
    public function zeroTargetDimensionsAreRejected(): void
    {
        self::assertNull($this->subject->generate($this->jpeg(400, 300), 0, 0));
    }

    /**
     * A structurally valid JPEG padded past PreviewGenerator's byte cap
     * with trailing zero bytes after the JPEG's own end-of-image marker.
     * The header stays intact, so a decoder still accepts the file; only
     * a guard that runs before decoding can catch it.
     */
    private function oversizedButValidJpeg(): string
    {
        $jpeg = $this->jpeg(400, 300);

        return $jpeg.str_repeat('0', max(0, 2_097_153 - strlen($jpeg)));
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($image, 200, 120, 40) ?: 0;
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $color);
        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }

    /**
     * Builds a source that is red everywhere except a centered green
     * stripe as wide as the source height, which is exactly the region
     * PreviewGenerator's crop() selects when squaring this 4:1 source.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function sourceWithCenteredGreenStripe(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($image, 220, 20, 20) ?: 0;
        $green = imagecolorallocate($image, 20, 200, 20) ?: 0;
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $red);
        $stripeX = (int) round(($width - $height) / 2);
        imagefilledrectangle($image, $stripeX, 0, $stripeX + $height - 1, $height - 1, $green);
        ob_start();
        imagejpeg($image, null, 100);

        return (string) ob_get_clean();
    }
}
