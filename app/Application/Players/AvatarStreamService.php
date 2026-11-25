<?php

namespace App\Application\Players;

use App\Domain\Players\Models\PlayerProfile;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AvatarStreamService
{
    public function __construct(
        private readonly FilesystemFactory $storage,
    ) {}

    /**
     * Streams the trainer's avatar from its private disk, or null when none is available.
     */
    public function respond(int $userId): ?StreamedResponse
    {
        $profile = PlayerProfile::query()->where('user_id', $userId)->first();

        if ($profile === null || ! $profile->hasAvatar()) {
            return null;
        }

        try {
            $disk = $this->storage->disk($profile->avatar_disk);

            if (! $disk->exists($profile->avatar_path)) {
                return null;
            }

            return $disk->response($profile->avatar_path, null, [
                'Cache-Control' => 'public, max-age=86400, immutable',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
