<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\PlayerProfileRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

class UpdatePasswordRequest extends FormRequest
{
    use PlayerProfileRules;

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
            // Checked against the authenticated user directly so it works for cookie sessions and bearer tokens.
            'current_password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! Hash::check($value, (string) $this->user()?->password)) {
                        $fail(__('validation.current_password'));
                    }
                },
            ],
            'password' => ['required', 'confirmed', 'different:current_password', ...$this->passwordRules()],
        ];
    }
}
