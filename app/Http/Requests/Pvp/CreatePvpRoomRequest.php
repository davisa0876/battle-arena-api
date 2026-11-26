<?php

namespace App\Http\Requests\Pvp;

use Illuminate\Foundation\Http\FormRequest;

class CreatePvpRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invitee_handle' => ['required', 'string', 'min:3', 'max:24', 'regex:/^[a-zA-Z0-9_]+$/'],
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
