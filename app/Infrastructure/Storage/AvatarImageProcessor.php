<?php

namespace App\Infrastructure\Storage;

use App\Application\Players\Exceptions\AvatarProcessingException;
use GdImage;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Decodes an uploaded JPEG/PNG/WebP and re-encodes it as a square, metadata-free image (GD).
 * Anything that is not a decodable raster of an allowed type (SVG, polyglots, ...) is rejected.
 */
class AvatarImageProcessor
{
    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(
        private readonly Config $config,
    ) {}

    public function isAvailable(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * @throws AvatarProcessingException
     */
    public function process(string $binary): ProcessedAvatar
    {
        if (! $this->isAvailable()) {
            throw AvatarProcessingException::processorUnavailable();
        }

        $info = @getimagesizefromstring($binary);
        if ($info === false || ! in_array($info[2], self::ALLOWED_TYPES, true)) {
            throw AvatarProcessingException::invalidImage();
        }

        $source = @imagecreatefromstring($binary);
        if (! $source instanceof GdImage) {
            throw AvatarProcessingException::invalidImage();
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $source = $this->applyExifOrientation($source, $binary);
        }

        $output = $this->squareThumbnail($source, (int) $this->config->get('game.avatar.output_dimension', 512));

        return $this->encode($output);
    }

    private function squareThumbnail(GdImage $source, int $size): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $target = imagecreatetruecolor($size, $size);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 255, 255, 255, 127));

        imagecopyresampled(
            $target,
            $source,
            0,
            0,
            intdiv($width - $side, 2),
            intdiv($height - $side, 2),
            $size,
            $size,
            $side,
            $side,
        );

        return $target;
    }

    private function encode(GdImage $image): ProcessedAvatar
    {
        ob_start();

        if (function_exists('imagewebp')) {
            imagewebp($image, null, 85);
            $extension = 'webp';
            $mime = 'image/webp';
        } else {
            // JPEG has no alpha: flatten onto white.
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            imagejpeg($flat, null, 85);
            $extension = 'jpg';
            $mime = 'image/jpeg';
        }

        $contents = (string) ob_get_clean();

        if ($contents === '') {
            throw AvatarProcessingException::invalidImage();
        }

        return new ProcessedAvatar($contents, $extension, $mime);
    }

    private function applyExifOrientation(GdImage $image, string $binary): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($binary));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
