<?php

namespace Tests\Feature\Telescope;

use Tests\TestCase;

class TelescopeAccessTest extends TestCase
{
    public function test_telescope_is_disabled_in_the_testing_environment(): void
    {
        $this->assertFalse((bool) config('telescope.enabled'));

        $this->get('/telescope')->assertNotFound();
    }
}
