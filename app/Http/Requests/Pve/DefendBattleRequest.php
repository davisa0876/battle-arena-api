<?php

namespace App\Http\Requests\Pve;

use Illuminate\Foundation\Http\FormRequest;

final class DefendBattleRequest extends FormRequest
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
            'defense' => ['required', 'string', 'in:endure,block,dodge,recover'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
