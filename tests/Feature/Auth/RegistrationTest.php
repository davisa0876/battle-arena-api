<?php

namespace Tests\Feature\Auth;

use App\Application\Authentication\RegisterUserService;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_web_registration_creates_user_profile_and_progress_and_starts_a_session(): void
    {
        Notification::fake();

        $response = $this->withHeaders($this->webHeaders())
            ->postJson('/api/register', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonPath('meta.auth_mode', 'session')
            ->assertJsonMissingPath('meta.token')
            ->assertJsonPath('data.email', 'ash@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.profile.handle', 'ashketchum')
            ->assertJsonPath('data.profile.display_name', 'Ash')
            ->assertJsonPath('data.profile.collection_is_public', false)
            ->assertJsonPath('data.trainer.level', 1)
            ->assertJsonPath('data.trainer.total_xp', 0)
            ->assertJsonPath('data.onboarding.starter_selected', false);

        $response->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'ash@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertSame('ashketchum', $user->profile->handle);
        $this->assertSame(1, $user->progress->level);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_mobile_registration_returns_a_bearer_token_that_authenticates(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/register', $this->registrationPayload(['device_name' => 'Pixel 9']))
            ->assertCreated()
            ->assertJsonPath('meta.auth_mode', 'token')
            ->assertJsonPath('meta.token_type', 'Bearer');

        $token = $response->json('meta.token');
        $this->assertNotEmpty($token);
        $this->assertGuest('web');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.profile.handle', 'ashketchum');
    }

    public function test_display_name_defaults_to_the_handle(): void
    {
        Notification::fake();

        $this->withHeaders($this->webHeaders())
            ->postJson('/api/register', $this->registrationPayload(['display_name' => null]))
            ->assertCreated()
            ->assertJsonPath('data.profile.display_name', 'ashketchum');
    }

    public function test_registration_rolls_back_the_user_when_the_profile_cannot_be_created(): void
    {
        Notification::fake();
        $this->makePlayer(['handle' => 'taken']);
        $users = User::query()->count();

        // Call the service directly to bypass request validation and hit the DB unique index.
        $request = Request::create('/api/register', 'POST');

        try {
            app(RegisterUserService::class)->register([
                'email' => 'ash@example.com',
                'password' => 'Passw0rd!x',
                'handle' => 'TAKEN',
                'device_name' => 'phpunit',
            ], $request);
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }

        $this->assertSame($users, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'ash@example.com']);
        Notification::assertNothingSent();
    }

    public function test_email_must_be_unique_case_insensitively(): void
    {
        Notification::fake();
        User::factory()->withPlayer()->create(['email' => 'ash@example.com']);

        $this->withHeaders($this->webHeaders())
            ->postJson('/api/register', $this->registrationPayload(['email' => 'ASH@EXAMPLE.COM']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_handle_must_be_unique_case_insensitively(): void
    {
        Notification::fake();
        $this->makePlayer(['handle' => 'ashketchum']);

        $this->withHeaders($this->webHeaders())
            ->postJson('/api/register', $this->registrationPayload(['handle' => 'ASHKetchum', 'email' => 'other@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('handle');
    }

    public function test_invalid_handles_are_rejected(): void
    {
        foreach (['ab', 'has space', 'bad-dash', 'emoji😀', str_repeat('a', 25)] as $handle) {
            $this->withHeaders($this->webHeaders())
                ->postJson('/api/register', $this->registrationPayload(['handle' => $handle]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('handle');
        }
    }

    public function test_weak_or_unconfirmed_passwords_are_rejected(): void
    {
        $this->withHeaders($this->webHeaders())
            ->postJson('/api/register', $this->registrationPayload(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->withHeaders($this->webHeaders())
            ->postJson('/api/register', $this->registrationPayload(['password_confirmation' => 'Different1!']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_non_browser_clients_must_send_a_device_name(): void
    {
        $this->postJson('/api/register', $this->registrationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device_name');
    }

    public function test_registration_is_rate_limited(): void
    {
        $limit = (int) config('game.rate_limits.register');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/register', [])->assertStatus(429);
    }

    public function test_registration_validation_messages_are_localized(): void
    {
        $this->withHeaders($this->webHeaders() + ['Accept-Language' => 'fr'])
            ->postJson('/api/register', $this->registrationPayload(['handle' => 'Bad Handle']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.handle.0', 'Le champ pseudo ne peut contenir que des lettres minuscules, des chiffres et des tirets bas.');
    }
}
