<?php

namespace Tests\Feature\Telescope;

use App\Providers\TelescopeServiceProvider;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class TelescopeAccessTest extends TestCase
{
    #[Test]
    public function test_telescope_is_disabled_in_the_testing_environment(): void
    {
        $this->assertFalse((bool) config('telescope.enabled'));

        $this->get('/telescope')->assertNotFound();
    }

    #[Test]
    public function test_loopback_hosts_are_allowed_for_local_ui(): void
    {
        $provider = new TelescopeServiceProvider($this->app);
        $method = new ReflectionMethod(TelescopeServiceProvider::class, 'isLoopbackHost');

        $this->assertTrue($method->invoke($provider, Request::create('http://localhost:8000/telescope')));
        $this->assertTrue($method->invoke($provider, Request::create('http://127.0.0.1:8000/telescope')));
        $this->assertFalse($method->invoke($provider, Request::create('http://192.168.1.10:8000/telescope')));
        $this->assertFalse($method->invoke($provider, Request::create('http://api.example.test/telescope')));
    }
}
