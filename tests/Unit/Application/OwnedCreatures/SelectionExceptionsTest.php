<?php

namespace Tests\Unit\Application\OwnedCreatures;

use App\Application\OwnedCreatures\Exceptions\CreatureSelectionException;
use App\Application\OwnedCreatures\Exceptions\StarterSelectionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SelectionExceptionsTest extends TestCase
{
    #[Test]
    public function test_creature_selection_exception_factories(): void
    {
        foreach ([
            CreatureSelectionException::noAllowance(2),
            CreatureSelectionException::stageLocked(3),
            CreatureSelectionException::alreadyOwned(),
            CreatureSelectionException::unavailable(),
            CreatureSelectionException::duplicateRequest(),
        ] as $exception) {
            $response = $exception->render();
            $this->assertGreaterThanOrEqual(403, $response->getStatusCode());
            $this->assertArrayHasKey('message', $response->getData(true));
        }
    }

    #[Test]
    public function test_starter_selection_exception_factories(): void
    {
        foreach ([
            StarterSelectionException::alreadySelected(),
            StarterSelectionException::notAllowed(),
            StarterSelectionException::unavailable(),
        ] as $exception) {
            $response = $exception->render();
            $this->assertGreaterThanOrEqual(409, $response->getStatusCode());
            $this->assertArrayHasKey('message', $response->getData(true));
        }
    }
}
