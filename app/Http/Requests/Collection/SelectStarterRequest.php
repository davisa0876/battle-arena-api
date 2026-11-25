<?php

namespace App\Http\Requests\Collection;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectStarterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('creature_slug'))) {
            $this->merge(['creature_slug' => mb_strtolower(trim($this->input('creature_slug')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'creature_slug' => ['required', 'string', Rule::in(config('game.starters.creature_slugs'))],
        ];
    }
}
