<?php

namespace App\Http\Resources;

use App\Application\Realtime\DTOs\RealtimeTicketResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RealtimeTicketResult
 */
class RealtimeTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RealtimeTicketResult $ticket */
        $ticket = $this->resource;

        return [
            'ticket' => $ticket->ticket,
            'expires_in' => $ticket->expiresIn,
            'ws_url' => $ticket->wsUrl,
        ];
    }
}
