<?php

namespace App\Http\Requests\Collection;

use Illuminate\Foundation\Http\FormRequest;

class AcquireCreatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'creature_slug' => ['required', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'creature_slug' => __('validation.attributes.creature_slug'),
            'idempotency_key' => __('validation.attributes.idempotency_key'),
        ];
    }
}
