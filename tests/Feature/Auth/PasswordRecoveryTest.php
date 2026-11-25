<?php

namespace Tests\Feature\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_forgot_password_sends_a_link_pointing_at_the_frontend(): void
    {
        Notification::fake();
        $user = $this->makePlayer([], ['email' => 'ash@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'ASH@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'http://localhost:3000/reset-password?')
                && str_contains($url, 'token='.$notification->token)
                && str_contains($url, 'email=ash%40example.com');
        });
    }

    public function test_forgot_password_does_not_reveal_unknown_emails(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'ghost@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'If an account exists for that email, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        $limit = (int) config('game.rate_limits.password_recovery');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'ghost@example.com'])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'ghost@example.com'])->assertStatus(429);
    }

    public function test_reset_password_changes_the_password_and_revokes_tokens(): void
    {
        $user = $this->makePlayer([], ['email' => 'ash@example.com']);
        $user->createToken('phone');
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'ash@example.com',
            'password' => 'N3wPassword!',
            'password_confirmation' => 'N3wPassword!',
        ])->assertOk()->assertJsonPath('message', 'Your password has been reset.');

        $this->assertTrue(Hash::check('N3wPassword!', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/login', ['email' => 'ash@example.com', 'password' => 'N3wPassword!', 'device_name' => 'x'])->assertOk();
    }

    public function test_reset_password_rejects_invalid_tokens_and_weak_passwords(): void
    {
        $user = $this->makePlayer([], ['email' => 'ash@example.com']);

        $this->postJson('/api/reset-password', [
            'token' => 'bogus',
            'email' => 'ash@example.com',
            'password' => 'N3wPassword!',
            'password_confirmation' => 'N3wPassword!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'ash@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_token_is_single_use(): void
    {
        $user = $this->makePlayer([], ['email' => 'ash@example.com']);
        $payload = [
            'token' => Password::createToken($user),
            'email' => 'ash@example.com',
            'password' => 'N3wPassword!',
            'password_confirmation' => 'N3wPassword!',
        ];

        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
    }
}
