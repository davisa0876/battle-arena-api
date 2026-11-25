<?php

namespace Tests\Support;

use App\Infrastructure\Storage\AvatarImageProcessor;
use App\Infrastructure\Storage\ProcessedAvatar;
use Illuminate\Http\UploadedFile;

/**
 * Builds valid PNG uploads without needing GD, and swaps in a passthrough avatar processor
 * when the GD extension is unavailable (e.g. before the Docker image is rebuilt).
 */
trait FakeImages
{
    protected function pngBinary(int $width = 64, int $height = 64, int $padKb = 0): string
    {
        $row = "\x00".str_repeat("\x20\x80\xC0", $width);
        $raw = str_repeat($row, $height);

        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $png = "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw))
            .$chunk('IEND', '');

        return $png.str_repeat("\x00", $padKb * 1024);
    }

    protected function pngUpload(string $name = 'avatar.png', int $width = 64, int $height = 64, int $padKb = 0): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->pngBinary($width, $height, $padKb));
    }

    protected function usePassthroughAvatarProcessorWhenGdMissing(): void
    {
        if (extension_loaded('gd')) {
            return;
        }

        $this->app->bind(AvatarImageProcessor::class, fn ($app) => new class($app['config']) extends AvatarImageProcessor
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function process(string $binary): ProcessedAvatar
            {
                return new ProcessedAvatar($binary, 'png', 'image/png');
            }
        });
    }
}
