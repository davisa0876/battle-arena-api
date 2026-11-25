<?php

namespace App\Application\Authentication;

use App\Application\Authentication\Exceptions\InvalidVerificationLinkException;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

class EmailVerificationService
{
    /**
     * Sends the verification email. Returns false when the address is already verified.
     */
    public function sendNotification(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        $user->sendEmailVerificationNotification();

        return true;
    }

    /**
     * Verifies the email behind a signed link (the `signed` middleware has already checked the signature).
     *
     * @throws InvalidVerificationLinkException when the user does not exist or the hash does not match
     */
    public function verify(int $userId, string $hash): User
    {
        $user = User::query()->find($userId);

        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new InvalidVerificationLinkException;
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $user;
    }
}
