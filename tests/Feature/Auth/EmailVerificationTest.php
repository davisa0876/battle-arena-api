<?php

namespace Tests\Feature\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_verification_notification_is_sent_for_unverified_users(): void
    {
        Notification::fake();
        $user = $this->makePlayer([], ['email_verified_at' => null]);

        $this->withToken($user->createToken('x')->plainTextToken)
            ->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'A new verification link has been sent.');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_already_verified_users_do_not_receive_another_email(): void
    {
        Notification::fake();
        $user = $this->makePlayer();

        $this->withToken($user->createToken('x')->plainTextToken)
            ->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Your email address is already verified.');

        Notification::assertNothingSent();
    }

    public function test_sending_requires_authentication(): void
    {
        $this->postJson('/api/email/verification-notification')->assertUnauthorized();
    }

    public function test_signed_link_verifies_the_email_and_redirects_browsers_to_the_frontend(): void
    {
        $user = $this->makePlayer([], ['email_verified_at' => null]);

        $this->get($this->signedUrl($user))
            ->assertRedirect('http://localhost:3000/verify-email?status=verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_signed_link_returns_json_for_api_clients(): void
    {
        $user = $this->makePlayer([], ['email_verified_at' => null]);

        $this->getJson($this->signedUrl($user))
            ->assertOk()
            ->assertJsonPath('message', 'Your email address has been verified.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_link_with_wrong_hash_is_rejected(): void
    {
        $user = $this->makePlayer([], ['email_verified_at' => null]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1('someone-else@example.com'),
        ]);

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unsigned_and_expired_links_are_rejected(): void
    {
        $user = $this->makePlayer([], ['email_verified_at' => null]);
        $hash = sha1($user->email);

        $this->getJson("/api/email/verify/{$user->id}/{$hash}")->assertForbidden();

        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => $hash]);
        $this->getJson($expired)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_email_contains_a_signed_api_url(): void
    {
        Notification::fake();
        $user = $this->makePlayer([], ['email_verified_at' => null]);

        $this->withToken($user->createToken('x')->plainTextToken)->postJson('/api/email/verification-notification');

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_contains($url, '/api/email/verify/'.$user->id.'/'.sha1($user->email))
                && str_contains($url, 'signature=');
        });
    }

    private function signedUrl($user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
    }
}
