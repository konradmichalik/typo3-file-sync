<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_file_sync" TYPO3 CMS extension.
 *
 * (c) 2025 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3FileSync\Resource\Handler;

use KonradMichalik\Typo3FileSync\Resource\RemoteResourceInterface;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PlaceholderImageResource implements RemoteResourceInterface
{
    /**
     * @var list<string>
     */
    protected array $allowedFileExtensions = [
        'avif',
        'gif',
        'jpeg',
        'jpg',
        'png',
        'svg',
        'webp',
    ];

    protected readonly string $backgroundColor;
    protected readonly string $textColor;

    public function __construct(array|string|null $configuration)
    {
        if (!is_array($configuration)) {
            $colors = GeneralUtility::trimExplode(',', (string)$configuration);
            $configuration = [
                'backgroundColor' => '#' . ltrim($colors[0] ?? 'CCCCCC', '#'),
                'textColor' => '#' . ltrim($colors[1] ?? '969696', '#'),
            ];
        }

        $this->backgroundColor = $configuration['backgroundColor'] ?? '#CCCCCC';
        $this->textColor = $configuration['textColor'] ?? '#969696';
    }

    public function hasFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): bool
    {
        return $fileObject instanceof FileInterface
            && in_array($fileObject->getExtension(), $this->allowedFileExtensions, true);
    }

    /**
     * @return string|false
     */
    public function getFile(string $fileIdentifier, string $filePath, ?FileInterface $fileObject = null): string|false
    {
        if (!$fileObject instanceof FileInterface) {
            return false;
        }

        $extension = $fileObject->getExtension();
        $width = max(1, (int)$fileObject->getProperty('width'));
        $height = max(1, (int)$fileObject->getProperty('height'));

        if ($extension === 'svg') {
            return $this->generateSvg($width, $height);
        }

        return $this->generateGdImage($width, $height, $extension);
    }

    private function generateSvg(int $width, int $height): string
    {
        $fontSize = max(10, (int)floor($width / 12));

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d">'
            . '<rect width="100%%" height="100%%" fill="%s"/>'
            . '<text x="50%%" y="50%%" dominant-baseline="central" text-anchor="middle" '
            . 'font-family="sans-serif" font-size="%d" fill="%s">%d x %d</text>'
            . '</svg>',
            $width,
            $height,
            $this->backgroundColor,
            $fontSize,
            $this->textColor,
            $width,
            $height
        );
    }

    private function generateGdImage(int $width, int $height, string $extension): string|false
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            return false;
        }

        $bgColor = $this->parseHexColor($image, $this->backgroundColor);
        $txtColor = $this->parseHexColor($image, $this->textColor);

        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $bgColor);

        $text = sprintf('%d x %d', $width, $height);
        $fontSize = max(1, (int)floor($width / 10));

        if ($fontSize <= 5) {
            $fontWidth = imagefontwidth($fontSize) * strlen($text);
            $fontHeight = imagefontheight($fontSize);
            $x = (int)floor(($width - $fontWidth) / 2);
            $y = (int)floor(($height - $fontHeight) / 2);
            imagestring($image, $fontSize, $x, $y, $text, $txtColor);
        } else {
            $builtInFont = 5;
            $fontWidth = imagefontwidth($builtInFont) * strlen($text);
            $fontHeight = imagefontheight($builtInFont);
            $x = (int)floor(($width - $fontWidth) / 2);
            $y = (int)floor(($height - $fontHeight) / 2);
            imagestring($image, $builtInFont, $x, $y, $text, $txtColor);
        }

        ob_start();
        match ($extension) {
            'gif' => imagegif($image),
            'png' => imagepng($image),
            'webp' => imagewebp($image),
            'avif' => function_exists('imageavif') ? imageavif($image) : imagepng($image),
            default => imagejpeg($image, null, 80),
        };
        $content = ob_get_clean();

        return $content !== false ? $content : false;
    }

    /**
     * @return int<0, max>
     */
    private function parseHexColor(\GdImage $image, string $hex): int
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return imagecolorallocate($image, (int)$r, (int)$g, (int)$b) ?: 0;
    }
}
