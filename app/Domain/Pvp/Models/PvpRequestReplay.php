<?php

namespace App\Domain\Pvp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpRequestReplay extends Model
{
    use HasUuids;

    protected $table = 'pvp_request_replays';

    protected $fillable = [
        'user_id',
        'operation',
        'idempotency_key',
        'request_fingerprint',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'request_fingerprint' => 'array',
            'response_body' => 'array',
            'response_status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
