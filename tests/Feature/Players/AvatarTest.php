<?php

namespace Tests\Feature\Players;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeImages;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use FakeImages;
    use MakesPlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usePassthroughAvatarProcessorWhenGdMissing();
        Storage::fake('profiles_local');
    }

    public function test_upload_stores_a_private_object_under_a_random_name_and_exposes_an_api_url(): void
    {
        $user = $this->makePlayer(['handle' => 'misty']);

        $response = $this->withHeaders($this->bearerFor($user))
            ->post('/api/me/avatar', ['avatar' => $this->pngUpload('my-secret-name.png')], ['Accept' => 'application/json'])
            ->assertOk();

        $profile = $user->profile()->first();
        $this->assertSame('profiles_local', $profile->avatar_disk);
        $this->assertStringStartsWith('avatars/', $profile->avatar_path);
        $this->assertStringNotContainsString('my-secret-name', $profile->avatar_path);
        Storage::disk('profiles_local')->assertExists($profile->avatar_path);

        $url = $response->json('data.profile.avatar_url');
        $this->assertStringContainsString('/api/avatars/'.$user->id, $url);
        $this->assertStringNotContainsString($profile->avatar_path, $url);
    }

    public function test_avatar_is_reencoded_to_a_square_image_when_gd_is_available(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is not installed in this image.');
        }

        $user = $this->makePlayer();
        $upload = $this->pngUpload('wide.png', 900, 300);

        $this->withHeaders($this->bearerFor($user))
            ->post('/api/me/avatar', ['avatar' => $upload], ['Accept' => 'application/json'])
            ->assertOk();

        $stored = Storage::disk('profiles_local')->get($user->profile->avatar_path);
        $size = getimagesizefromstring($stored);

        $this->assertSame(512, $size[0]);
        $this->assertSame(512, $size[1]);
        $this->assertNotSame($this->pngBinary(900, 300), $stored);
    }

    public function test_replacing_an_avatar_deletes_the_previous_object(): void
    {
        $user = $this->makePlayer();
        $headers = $this->bearerFor($user) + ['Accept' => 'application/json'];

        $this->withHeaders($headers)->post('/api/me/avatar', ['avatar' => $this->pngUpload()])->assertOk();
        $first = $user->profile()->first()->avatar_path;

        $this->withHeaders($headers)->post('/api/me/avatar', ['avatar' => $this->pngUpload('second.png')])->assertOk();
        $second = $user->profile()->first()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('profiles_local')->assertMissing($first);
        Storage::disk('profiles_local')->assertExists($second);
    }

    public function test_avatar_can_be_removed(): void
    {
        $user = $this->makePlayer();
        $headers = $this->bearerFor($user) + ['Accept' => 'application/json'];
        $this->withHeaders($headers)->post('/api/me/avatar', ['avatar' => $this->pngUpload()])->assertOk();
        $path = $user->profile()->first()->avatar_path;

        $this->withHeaders($headers)->deleteJson('/api/me/avatar')
            ->assertOk()
            ->assertJsonPath('data.profile.avatar_url', null);

        Storage::disk('profiles_local')->assertMissing($path);
        $this->assertDatabaseHas('player_profiles', ['user_id' => $user->id, 'avatar_path' => null, 'avatar_disk' => null]);
    }

    public function test_avatar_stream_serves_the_image_publicly(): void
    {
        $user = $this->makePlayer(['handle' => 'misty']);
        $this->withHeaders($this->bearerFor($user) + ['Accept' => 'application/json'])
            ->post('/api/me/avatar', ['avatar' => $this->pngUpload()])
            ->assertOk();

        $stored = Storage::disk('profiles_local')->get($user->profile()->first()->avatar_path);

        $this->app['auth']->forgetGuards();
        $response = $this->get('/api/avatars/'.$user->id)->assertOk();

        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame($stored, $response->streamedContent());

        $this->getJson('/api/players/misty')->assertJsonPath('data.avatar_url', fn ($url) => str_contains((string) $url, '/api/avatars/'.$user->id));
    }

    public function test_avatar_stream_returns_404_without_an_avatar_or_profile(): void
    {
        $user = $this->makePlayer();

        $this->getJson('/api/avatars/'.$user->id)->assertNotFound();
        $this->getJson('/api/avatars/999999')->assertNotFound();
    }

    public function test_svg_uploads_are_rejected(): void
    {
        $user = $this->makePlayer();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><script>alert(1)</script></svg>';

        $this->withHeaders($this->bearerFor($user) + ['Accept' => 'application/json'])
            ->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('avatar.svg', $svg)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->withHeaders($this->bearerFor($user) + ['Accept' => 'application/json'])
            ->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('avatar.png', $svg)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertEmpty(Storage::disk('profiles_local')->allFiles());
    }

    public function test_non_images_with_image_extensions_are_rejected(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user) + ['Accept' => 'application/json'])
            ->post('/api/me/avatar', ['avatar' => UploadedFile::fake()->createWithContent('shell.png', '<?php echo "pwned";')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_oversized_files_and_dimensions_are_rejected(): void
    {
        $user = $this->makePlayer();
        $headers = $this->bearerFor($user) + ['Accept' => 'application/json'];

        $this->withHeaders($headers)
            ->post('/api/me/avatar', ['avatar' => $this->pngUpload('big.png', 64, 64, 2100)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->withHeaders($headers)
            ->post('/api/me/avatar', ['avatar' => $this->pngUpload('wide.png', 1100, 100)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->withHeaders($headers)
            ->post('/api/me/avatar', ['avatar' => $this->pngUpload('tiny.png', 8, 8)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_avatar_field_is_required(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/avatar', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_configured_s3_style_disk_is_used_when_profiles_disk_is_switched(): void
    {
        config(['game.avatar.disk' => 'profiles']);
        Storage::fake('profiles');
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user) + ['Accept' => 'application/json'])
            ->post('/api/me/avatar', ['avatar' => $this->pngUpload()])
            ->assertOk();

        $profile = $user->profile()->first();
        $this->assertSame('profiles', $profile->avatar_disk);
        Storage::disk('profiles')->assertExists($profile->avatar_path);
        Storage::disk('profiles_local')->assertMissing($profile->avatar_path);

        $this->get('/api/avatars/'.$user->id)->assertOk();
    }
}
