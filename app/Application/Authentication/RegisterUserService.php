<?php

namespace App\Application\Authentication;

use App\Application\Authentication\DTOs\AuthenticationResult;
use App\Domain\Players\Models\PlayerProfile;
use App\Domain\Progression\Models\PlayerProgress;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterUserService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly LoginService $login,
    ) {}

    /**
     * Creates the user, profile and level-1 progress atomically, then authenticates them.
     *
     * @param  array{email: string, password: string, handle: string, display_name?: string|null, device_name?: string|null}  $data
     */
    public function register(array $data, Request $request): AuthenticationResult
    {
        try {
            $user = $this->db->transaction(function () use ($data): User {
                $handle = PlayerProfile::normalizeHandle($data['handle']);
                $displayName = trim((string) ($data['display_name'] ?? '')) ?: $handle;

                $user = User::query()->create([
                    'name' => $displayName,
                    'email' => User::normalizeEmail($data['email']),
                    'password' => $data['password'],
                ]);

                PlayerProfile::query()->create([
                    'user_id' => $user->id,
                    'handle' => $handle,
                    'display_name' => $displayName,
                ]);

                PlayerProgress::query()->create([
                    'user_id' => $user->id,
                    'level' => 1,
                    'total_xp' => 0,
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => [__('api.registration_conflict')],
            ]);
        }

        try {
            event(new Registered($user));
        } catch (Throwable $e) {
            // A mail outage must not undo a successful registration; the user can resend verification.
            report($e);
        }

        return $this->login->startAuthenticatedSession($user, $data['device_name'] ?? null, $request);
    }
}
