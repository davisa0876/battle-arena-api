<?php

namespace App\Http\Requests\Pvp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPvpInvitationsRequest extends FormRequest
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
            'box' => ['sometimes', 'string', Rule::in(['received', 'sent'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
