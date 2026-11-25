<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class AvatarUploadRequest extends FormRequest
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
        $config = config('game.avatar');

        return [
            'avatar' => [
                'required',
                'file',
                'mimes:'.implode(',', $config['allowed_extensions']),
                'mimetypes:'.implode(',', $config['allowed_mime_types']),
                'max:'.$config['max_upload_kb'],
                sprintf(
                    'dimensions:min_width=%1$d,min_height=%1$d,max_width=%2$d,max_height=%2$d',
                    $config['min_dimension'],
                    $config['max_dimension'],
                ),
            ],
        ];
    }
}
