<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TripRequest extends FormRequest
{
    /**
     * Fields the form sends once per language, as `title[en]` / `title[dv]`.
     *
     * @var array<string, int>
     */
    private const TRANSLATABLE = [
        'title' => 255,
        'location' => 255,
        'summary' => 1000,
        'details' => 0,
    ];

    /**
     * The route group and the controller's policy both gate this already;
     * returning true here defers to them rather than adding a third, weaker
     * answer that could drift out of step with the other two.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept a plain `title=...` as English.
     *
     * The form sends `title[en]`, but the field was a bare string for the
     * whole life of the panel, and anything still posting one means English —
     * the alternative was a validation error an editor could not read.
     */
    protected function prepareForValidation(): void
    {
        $normalised = [];

        foreach (array_keys(self::TRANSLATABLE) as $field) {
            if ($this->has($field) && ! is_array($this->input($field))) {
                $normalised[$field] = ['en' => $this->input($field)];
            }
        }

        if ($normalised !== []) {
            $this->merge($normalised);
        }
    }

    public function rules(): array
    {
        $tripId = $this->route('trip')?->id;

        $rules = [
            'slug' => [
                'nullable', // Will be auto-generated
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('trips')->ignore($tripId),
            ],
            'date_start' => ['required', 'date'],
            'date_end' => ['required', 'date', 'after_or_equal:date_start'],
            'price_from_mvr' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(['current', 'upcoming', 'past'])],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:6144'],
            'is_published' => ['boolean'],
        ];

        foreach (self::TRANSLATABLE as $field => $max) {
            $length = $max > 0 ? ['max:'.$max] : [];

            $rules[$field] = [$field === 'title' ? 'required' : 'nullable', 'array'];

            // English is the site's fallback, so a trip without it would show
            // a blank card to every visitor who is not reading Dhivehi.
            $rules[$field.'.en'] = array_merge(
                [$field === 'title' ? 'required' : 'nullable', 'string'], $length,
            );

            // Dhivehi is always optional. Leaving it out means the English
            // text is shown; it never means the trip cannot be saved.
            $rules[$field.'.dv'] = array_merge(['nullable', 'string'], $length);
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'title.en' => __('trip title (English)'),
            'title.dv' => __('trip title (Dhivehi)'),
            'location.en' => __('location (English)'),
            'location.dv' => __('location (Dhivehi)'),
            'summary.en' => __('summary (English)'),
            'summary.dv' => __('summary (Dhivehi)'),
            'details.en' => __('details (English)'),
            'details.dv' => __('details (Dhivehi)'),
        ];
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
