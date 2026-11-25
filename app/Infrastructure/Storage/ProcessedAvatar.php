<?php

namespace App\Infrastructure\Storage;

final readonly class ProcessedAvatar
{
    public function __construct(
        public string $contents,
        public string $extension,
        public string $mimeType,
    ) {}
}
