<?php

namespace App\Http\Requests\Pve;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AttackBattleRequest extends FormRequest
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
            'move_id' => ['nullable', 'integer', 'min:1'],
            'basic_strike' => ['sometimes', 'boolean'],
            'recover' => ['sometimes', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $moveId = $this->input('move_id');
            $basic = filter_var($this->input('basic_strike'), FILTER_VALIDATE_BOOLEAN);
            $recover = filter_var($this->input('recover'), FILTER_VALIDATE_BOOLEAN);
            $selected = (int) ($moveId !== null && $moveId !== '') + (int) $basic + (int) $recover;

            if ($selected !== 1) {
                $validator->errors()->add(
                    'move_id',
                    'Provide exactly one of move_id, basic_strike, or recover.',
                );
            }
        });
    }
}
