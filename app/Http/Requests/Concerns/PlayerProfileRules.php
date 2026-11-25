<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Players\Models\PlayerProfile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Shared validation rules and input normalisation for player-facing forms.
 * Handles and emails are lowercased before validation so plain unique rules are case-insensitive.
 */
trait PlayerProfileRules
{
    /**
     * @return list<mixed>
     */
    protected function handleRules(?int $ignoreProfileId = null): array
    {
        return [
            'string',
            'min:3',
            'max:24',
            'regex:/^[a-z0-9_]+$/',
            Rule::unique('player_profiles', 'handle')->ignore($ignoreProfileId),
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function passwordRules(): array
    {
        return ['string', 'max:72', Password::min(8)->letters()->numbers()];
    }

    protected function normalizeHandleInput(): void
    {
        if (is_string($this->input('handle'))) {
            $this->merge(['handle' => PlayerProfile::normalizeHandle($this->input('handle'))]);
        }
    }

    protected function normalizeEmailInput(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
