<?php

namespace App\Application\Players;

use App\Application\Players\Exceptions\AvatarProcessingException;
use App\Domain\Players\Models\PlayerProfile;
use App\Infrastructure\Storage\AvatarImageProcessor;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

class AvatarUploadService
{
    public function __construct(
        private readonly AvatarImageProcessor $processor,
        private readonly FilesystemFactory $storage,
        private readonly Config $config,
    ) {}

    /**
     * Re-encodes the upload, stores it under a random name on the configured private disk,
     * then removes the previous avatar.
     *
     * @throws AvatarProcessingException
     */
    public function store(User $user, UploadedFile $file): PlayerProfile
    {
        $profile = $user->profile()->firstOrFail();
        $processed = $this->processor->process((string) $file->get());

        $diskName = (string) $this->config->get('game.avatar.disk', 'profiles_local');
        $path = 'avatars/'.Str::lower((string) Str::ulid()).'.'.$processed->extension;

        try {
            $written = $this->storage->disk($diskName)->put($path, $processed->contents, ['visibility' => 'private']);
        } catch (Throwable $e) {
            report($e);
            $written = false;
        }

        if ($written === false) {
            throw AvatarProcessingException::storageFailed();
        }

        $previous = [$profile->avatar_disk, $profile->avatar_path];

        $profile->forceFill(['avatar_disk' => $diskName, 'avatar_path' => $path])->save();

        $this->deleteStored(...$previous);

        return $profile;
    }

    public function remove(User $user): PlayerProfile
    {
        $profile = $user->profile()->firstOrFail();
        $previous = [$profile->avatar_disk, $profile->avatar_path];

        $profile->forceFill(['avatar_disk' => null, 'avatar_path' => null])->save();

        $this->deleteStored(...$previous);

        return $profile;
    }

    private function deleteStored(?string $disk, ?string $path): void
    {
        if ($disk === null || $path === null) {
            return;
        }

        try {
            $this->storage->disk($disk)->delete($path);
        } catch (Throwable $e) {
            // The profile no longer references the object; a failed cleanup must not fail the request.
            report($e);
        }
    }
}
