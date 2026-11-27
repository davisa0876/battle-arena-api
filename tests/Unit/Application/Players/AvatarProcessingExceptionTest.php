<?php

namespace Tests\Unit\Application\Players;

use App\Application\Players\Exceptions\AvatarProcessingException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AvatarProcessingExceptionTest extends TestCase
{
    #[Test]
    public function test_factory_methods_and_render_payloads(): void
    {
        $invalid = AvatarProcessingException::invalidImage();
        $this->assertSame(422, $invalid->render()->getStatusCode());
        $this->assertSame(__('api.avatar_invalid'), $invalid->render()->getData(true)['message']);

        $unavailable = AvatarProcessingException::processorUnavailable();
        $this->assertSame(503, $unavailable->render()->getStatusCode());
        $this->assertSame(__('api.avatar_processing_unavailable'), $unavailable->render()->getData(true)['message']);

        $storage = AvatarProcessingException::storageFailed();
        $this->assertSame(503, $storage->render()->getStatusCode());
        $this->assertSame(__('api.avatar_storage_failed'), $storage->render()->getData(true)['message']);
    }
}
