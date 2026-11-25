<?php

namespace App\Http\Requests\Pve;

use Illuminate\Foundation\Http\FormRequest;

final class StartBattleRequest extends FormRequest
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
            'owned_creature_id' => ['required', 'integer', 'min:1'],
            'difficulty' => ['sometimes', 'nullable', 'string', 'in:easy,normal'],
        ];
    }
}
