<?php

namespace App\Application\Authentication;

use App\Application\Authentication\DTOs\AuthenticationResult;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class LoginService
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Hasher $hasher,
    ) {}

    /**
     * @throws ValidationException when credentials are wrong
     */
    public function login(string $email, string $password, ?string $deviceName, Request $request): AuthenticationResult
    {
        $user = User::query()->where('email', User::normalizeEmail($email))->first();

        if ($user === null || ! $this->hasher->check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->startAuthenticatedSession($user, $deviceName, $request);
    }

    /**
     * Web (no device name): regenerate the cookie session. Mobile (device name): issue a personal access token.
     *
     * @throws ValidationException when no session is available and no device name was sent
     */
    public function startAuthenticatedSession(User $user, ?string $deviceName, Request $request): AuthenticationResult
    {
        if ($deviceName !== null && $deviceName !== '') {
            return new AuthenticationResult($user, $user->createToken($deviceName)->plainTextToken);
        }

        if (! $request->hasSession()) {
            throw ValidationException::withMessages([
                'device_name' => [__('auth.device_name_required')],
            ]);
        }

        $this->auth->guard('web')->login($user);
        $request->session()->regenerate();

        return new AuthenticationResult($user, null);
    }

    /**
     * Revokes the current bearer token, or ends the cookie session for web clients.
     */
    public function logout(User $user, Request $request): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return;
        }

        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            PersonalAccessToken::findToken($bearer)?->delete();

            return;
        }

        $this->auth->guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
