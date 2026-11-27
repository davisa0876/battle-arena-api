<?php

namespace App\Http\Requests\Pvp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DefendPvpBattleRequest extends FormRequest
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
            'defense' => ['required', 'string', Rule::in(['endure', 'block', 'dodge', 'recover', 'heal'])],
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
