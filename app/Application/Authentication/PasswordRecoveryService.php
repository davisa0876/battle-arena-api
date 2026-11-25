<?php

namespace App\Application\Authentication;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordRecoveryService
{
    public function __construct(
        private readonly PasswordBroker $broker,
    ) {}

    /**
     * Sends a reset link when the account exists. The outcome is deliberately not exposed
     * so the endpoint cannot be used to discover registered emails.
     */
    public function sendResetLink(string $email): void
    {
        $this->broker->sendResetLink(['email' => User::normalizeEmail($email)]);
    }

    /**
     * Resets the password and revokes every personal access token.
     *
     * @throws ValidationException when the token or email is invalid
     */
    public function reset(string $email, string $token, string $password): void
    {
        $status = $this->broker->reset(
            ['email' => User::normalizeEmail($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }
    }
}
