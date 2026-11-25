<?php

namespace App\Application\Players\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AvatarProcessingException extends RuntimeException
{
    private function __construct(string $message, private readonly string $translationKey, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function invalidImage(): self
    {
        return new self('The uploaded file is not a valid image.', 'api.avatar_invalid', 422);
    }

    public static function processorUnavailable(): self
    {
        return new self('The GD extension is not available.', 'api.avatar_processing_unavailable', 503);
    }

    public static function storageFailed(): self
    {
        return new self('The avatar could not be written to storage.', 'api.avatar_storage_failed', 503);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => __($this->translationKey)], $this->status);
    }
}
