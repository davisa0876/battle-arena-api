<?php

namespace App\Http\Requests\Pvp;

use Illuminate\Foundation\Http\FormRequest;

class ReadyPvpRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'expected_room_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function idempotencyKey(): ?string
    {
        $header = $this->header('Idempotency-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $body = $this->validated('idempotency_key');

        return is_string($body) && $body !== '' ? $body : null;
    }
}
