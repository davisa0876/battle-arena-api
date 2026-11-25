<?php

namespace Tests\Feature\Progression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class AwardXpCommandTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_command_awards_xp_by_handle_email_or_id(): void
    {
        $user = $this->makePlayer(['handle' => 'misty'], ['email' => 'misty@example.com']);

        $this->artisan('trainer:award-xp', ['user' => 'misty', 'amount' => 100, '--key' => 'a'])->assertSuccessful();
        $this->artisan('trainer:award-xp', ['user' => 'misty@example.com', 'amount' => 50, '--key' => 'b'])->assertSuccessful();
        $this->artisan('trainer:award-xp', ['user' => (string) $user->id, 'amount' => 25, '--key' => 'c', '--source' => 'qa'])->assertSuccessful();

        $progress = $user->fresh()->progress;
        $this->assertSame(175, $progress->total_xp);
        $this->assertSame(2, $progress->level);
        $this->assertDatabaseHas('player_xp_events', ['idempotency_key' => 'c', 'source_type' => 'qa']);
    }

    public function test_command_is_idempotent_for_the_same_key(): void
    {
        $user = $this->makePlayer(['handle' => 'misty']);

        $this->artisan('trainer:award-xp', ['user' => 'misty', 'amount' => 100, '--key' => 'same'])->assertSuccessful();
        $this->artisan('trainer:award-xp', ['user' => 'misty', 'amount' => 100, '--key' => 'same'])->assertSuccessful();

        $this->assertSame(100, $user->fresh()->progress->total_xp);
    }

    public function test_command_rejects_unknown_users_and_invalid_amounts(): void
    {
        $this->makePlayer(['handle' => 'misty']);

        $this->artisan('trainer:award-xp', ['user' => 'ghost', 'amount' => 10])->assertFailed();
        $this->artisan('trainer:award-xp', ['user' => 'misty', 'amount' => 'lots'])->assertFailed();
        $this->artisan('trainer:award-xp', ['user' => 'misty', 'amount' => -5])->assertFailed();
    }

    public function test_command_refuses_to_run_outside_local_and_testing(): void
    {
        $user = $this->makePlayer(['handle' => 'misty']);
        $this->app['env'] = 'production';

        $this->artisan('trainer:award-xp', ['user' => 'misty', 'amount' => 100])->assertFailed();

        $this->assertSame(0, $user->fresh()->progress->total_xp);
    }
}
