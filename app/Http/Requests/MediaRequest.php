<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trip_id' => ['nullable', 'exists:trips,id'],
            'type' => ['required', Rule::in(['photo', 'video'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'file_path' => [
                'required_if:type,photo',
                'nullable',
                'image',
                'mimes:jpeg,png,jpg,webp',
                'max:5120',
            ],
            'video_url' => [
                'required_if:type,video',
                'nullable',
                'url',
                'regex:/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\/.+/',
            ],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'file_path.required_if' => 'A photo file is required when type is photo.',
            'video_url.required_if' => 'A video URL is required when type is video.',
            'video_url.regex' => 'The video URL must be a valid YouTube URL.',
            'file_path.max' => 'The photo file size must not exceed 5MB.',
        ];
    }
}
