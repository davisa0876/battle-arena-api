<?php

namespace App\Application\Realtime\DTOs;

final readonly class RealtimeTicketResult
{
    public function __construct(
        public string $ticket,
        public int $expiresIn,
        public string $wsUrl,
    ) {}
}
