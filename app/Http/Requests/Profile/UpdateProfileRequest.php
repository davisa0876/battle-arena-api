<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\PlayerProfileRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    use PlayerProfileRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeHandleInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'handle' => ['sometimes', 'required', ...$this->handleRules($this->user()?->profile?->id)],
            'display_name' => ['sometimes', 'required', 'string', 'min:1', 'max:64'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'collection_is_public' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
