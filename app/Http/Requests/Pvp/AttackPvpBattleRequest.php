<?php

namespace App\Http\Requests\Pvp;

use Illuminate\Foundation\Http\FormRequest;

class AttackPvpBattleRequest extends FormRequest
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
            'move_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'basic_strike' => ['sometimes', 'boolean'],
            'recover' => ['sometimes', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function idempotencyKey(): string
    {
        $header = $this->header('Idempotency-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $body = $this->input('idempotency_key');
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return '';
    }
}
