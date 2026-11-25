<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\PlayerProfileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class RegisterRequest extends FormRequest
{
    use PlayerProfileRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();
        $this->normalizeHandleInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'handle' => ['required', ...$this->handleRules()],
            'display_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:64'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', ...$this->passwordRules()],
            'device_name' => [
                Rule::requiredIf(fn (): bool => ! EnsureFrontendRequestsAreStateful::fromFrontend($this)),
                'nullable', 'string', 'max:120',
            ],
        ];
    }

    /**
     * @return array{email: string, password: string, handle: string, display_name: ?string, device_name: ?string}
     */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'email' => $data['email'],
            'password' => $data['password'],
            'handle' => $data['handle'],
            'display_name' => $data['display_name'] ?? null,
            'device_name' => $data['device_name'] ?? null,
        ];
    }
}
