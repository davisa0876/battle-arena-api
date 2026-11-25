<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\PlayerProfileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class LoginRequest extends FormRequest
{
    use PlayerProfileRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => [
                Rule::requiredIf(fn (): bool => ! EnsureFrontendRequestsAreStateful::fromFrontend($this)),
                'nullable', 'string', 'max:120',
            ],
        ];
    }
}
