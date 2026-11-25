<?php

namespace Tests\Feature\Players;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_me_returns_account_profile_and_progress_without_secrets(): void
    {
        $user = $this->makePlayer(['handle' => 'misty', 'bio' => 'Water types']);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.profile.handle', 'misty')
            ->assertJsonPath('data.profile.bio', 'Water types')
            ->assertJsonPath('data.profile.avatar_url', null)
            ->assertJsonPath('data.trainer.level', 1)
            ->assertJsonPath('data.trainer.xp_for_next_level', 100)
            ->assertJsonPath('data.onboarding.starter_selected', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');
    }

    public function test_me_works_with_a_web_session(): void
    {
        $user = $this->makePlayer(['handle' => 'brock']);

        $this->actingAs($user, 'web')
            ->withHeaders($this->webHeaders())
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.profile.handle', 'brock');
    }

    public function test_profile_can_be_updated_partially(): void
    {
        $user = $this->makePlayer(['handle' => 'misty', 'display_name' => 'Misty']);

        $this->withHeaders($this->bearerFor($user))
            ->patchJson('/api/me/profile', [
                'display_name' => 'Misty W.',
                'bio' => 'Gym leader',
                'collection_is_public' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.handle', 'misty')
            ->assertJsonPath('data.profile.display_name', 'Misty W.')
            ->assertJsonPath('data.profile.bio', 'Gym leader')
            ->assertJsonPath('data.profile.collection_is_public', true);

        $this->assertSame('Misty W.', $user->fresh()->name);
    }

    public function test_handle_can_change_and_is_stored_lowercase(): void
    {
        $user = $this->makePlayer(['handle' => 'misty']);

        $this->withHeaders($this->bearerFor($user))
            ->patchJson('/api/me/profile', ['handle' => 'Misty_Water'])
            ->assertOk()
            ->assertJsonPath('data.profile.handle', 'misty_water');

        $this->assertDatabaseHas('player_profiles', ['user_id' => $user->id, 'handle' => 'misty_water']);
    }

    public function test_handle_cannot_collide_with_another_player_case_insensitively(): void
    {
        $this->makePlayer(['handle' => 'brock']);
        $user = $this->makePlayer(['handle' => 'misty']);

        $this->withHeaders($this->bearerFor($user))
            ->patchJson('/api/me/profile', ['handle' => 'BROCK'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('handle');
    }

    public function test_keeping_your_own_handle_is_not_a_conflict(): void
    {
        $user = $this->makePlayer(['handle' => 'misty']);

        $this->withHeaders($this->bearerFor($user))
            ->patchJson('/api/me/profile', ['handle' => 'MISTY', 'bio' => 'hi'])
            ->assertOk();
    }

    public function test_profile_validation(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->patchJson('/api/me/profile', [
                'bio' => str_repeat('x', 501),
                'display_name' => '',
                'collection_is_public' => 'maybe',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bio', 'display_name', 'collection_is_public']);
    }

    public function test_profile_endpoints_require_authentication(): void
    {
        $this->patchJson('/api/me/profile', ['bio' => 'x'])->assertUnauthorized();
        $this->patchJson('/api/me/password', [])->assertUnauthorized();
        $this->postJson('/api/me/avatar')->assertUnauthorized();
        $this->deleteJson('/api/me/avatar')->assertUnauthorized();
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->patchJson('/api/me/password', [
                'current_password' => 'wrong',
                'password' => 'N3wPassword!',
                'password_confirmation' => 'N3wPassword!',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_keeps_the_current_token_and_revokes_the_others(): void
    {
        $user = $this->makePlayer();
        $user->createToken('tablet');
        $current = $user->createToken('phone')->plainTextToken;

        $this->withToken($current)
            ->patchJson('/api/me/password', [
                'current_password' => 'password',
                'password' => 'N3wPassword!',
                'password_confirmation' => 'N3wPassword!',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('N3wPassword!', $user->fresh()->password));
        $this->assertSame(['phone'], $user->tokens()->pluck('name')->all());

        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/me')->assertOk();
    }

    public function test_new_password_must_differ_and_be_strong(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->patchJson('/api/me/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}
