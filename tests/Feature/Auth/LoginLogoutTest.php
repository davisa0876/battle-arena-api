<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class LoginLogoutTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_web_login_starts_a_cookie_session_without_a_token(): void
    {
        $user = $this->makePlayer([], ['email' => 'ash@example.com']);

        $this->withHeaders($this->webHeaders())
            ->postJson('/api/login', ['email' => 'ash@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('meta.auth_mode', 'session')
            ->assertJsonMissingPath('meta.token')
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_mobile_login_returns_a_token_and_never_a_session(): void
    {
        $user = $this->makePlayer([], ['email' => 'ash@example.com']);

        $response = $this->postJson('/api/login', [
            'email' => 'Ash@Example.com',
            'password' => 'password',
            'device_name' => 'iPhone',
        ])
            ->assertOk()
            ->assertJsonPath('meta.auth_mode', 'token');

        $this->assertGuest('web');
        $this->assertSame(1, $user->tokens()->where('name', 'iPhone')->count());

        $this->withToken($response->json('meta.token'))->getJson('/api/me')->assertOk();
    }

    public function test_wrong_password_and_unknown_email_share_the_same_error(): void
    {
        $this->makePlayer([], ['email' => 'ash@example.com']);

        $wrongPassword = $this->postJson('/api/login', ['email' => 'ash@example.com', 'password' => 'nope', 'device_name' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $unknown = $this->postJson('/api/login', ['email' => 'ghost@example.com', 'password' => 'nope', 'device_name' => 'x'])
            ->assertUnprocessable();

        $this->assertSame($wrongPassword->json('errors.email'), $unknown->json('errors.email'));
        $this->assertGuest('web');
    }

    public function test_non_browser_clients_must_send_a_device_name(): void
    {
        $this->makePlayer([], ['email' => 'ash@example.com']);

        $this->postJson('/api/login', ['email' => 'ash@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device_name');
    }

    public function test_login_is_rate_limited_per_email_and_ip(): void
    {
        $limit = (int) config('game.rate_limits.login');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/login', ['email' => 'ash@example.com', 'password' => 'wrong', 'device_name' => 'x'])
                ->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => 'ash@example.com', 'password' => 'wrong', 'device_name' => 'x'])
            ->assertStatus(429);
    }

    public function test_login_error_is_localized(): void
    {
        $this->withHeaders(['Accept-Language' => 'es'])
            ->postJson('/api/login', ['email' => 'ash@example.com', 'password' => 'wrong', 'device_name' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Estas credenciales no coinciden con nuestros registros.');
    }

    public function test_token_logout_revokes_only_the_current_token(): void
    {
        $user = $this->makePlayer();
        $current = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');

        $this->withToken($current)->postJson('/api/logout')->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('tablet', $user->tokens()->first()->name);

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_web_logout_ends_the_session(): void
    {
        $user = $this->makePlayer();

        $this->actingAs($user, 'web')
            ->withHeaders($this->webHeaders())
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertGuest('web');
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized()->assertJsonPath('message', 'Authentication is required.');
    }

    public function test_unauthenticated_message_is_localized_and_json_even_without_accept_header(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr'])
            ->get('/api/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Authentification requise.');
    }
}
