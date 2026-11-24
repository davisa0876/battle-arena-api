<?php

namespace App\Http\Requests;

use App\Application\Creatures\Services\CreatureCatalogService;
use Illuminate\Foundation\Http\FormRequest;

class CreatureIndexRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'type' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.CreatureCatalogService::MAX_PER_PAGE],
        ];
    }

    /**
     * @return array{search: ?string, type: ?string, per_page: ?int, page: ?int}
     */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'type' => $this->validated('type'),
            'per_page' => $this->validated('per_page'),
            'page' => $this->validated('page'),
        ];
    }
}
