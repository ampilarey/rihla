<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tripId = $this->route('trip')?->id;
        $locale = $this->input('locale');

        $rules = [
            'locale' => ['required', 'string', 'size:2', 'in:en,dv'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', // Will be auto-generated
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('trips')->ignore($tripId),
            ],
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date', 'after_or_equal:date_start'],
            'location' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'details' => ['nullable', 'string'],
            'price_from_mvr' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['current', 'upcoming', 'past'])],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:6144'],
            'is_published' => ['boolean'],
        ];

        // Add Dhivehi validation rules only when locale is 'dv'
        if ($locale === 'dv') {
            $rules['title_dv'] = ['required', 'string', 'max:255'];
            $rules['location_dv'] = ['required', 'string', 'max:255'];
            $rules['summary_dv'] = ['required', 'string', 'max:1000'];
            $rules['details_dv'] = ['required', 'string'];
        } else {
            $rules['title_dv'] = ['nullable', 'string', 'max:255'];
            $rules['location_dv'] = ['nullable', 'string', 'max:255'];
            $rules['summary_dv'] = ['nullable', 'string', 'max:1000'];
            $rules['details_dv'] = ['nullable', 'string'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug must contain only lowercase letters, numbers, and hyphens.',
            'date_end.after_or_equal' => 'The end date must be after or equal to the start date.',
            'price_from_mvr.min' => 'The price must be at least 0.',
        ];
    }
}
