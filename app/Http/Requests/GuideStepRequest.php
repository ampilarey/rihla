<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesTranslations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One guide step, in both languages.
 *
 * This replaces two identical copies of the same rules inside the controller's
 * store() and update(), which had already drifted apart once.
 */
class GuideStepRequest extends FormRequest
{
    use NormalisesTranslations;

    /**
     * Text fields the form sends once per language, and their length limit.
     *
     * @var array<string, int>
     */
    private const TRANSLATABLE = [
        'title' => 120,
        'summary' => 0,
        'details' => 0,
        'reference_text' => 500,
    ];

    /**
     * List fields: one item per line in the textarea, a JSON array in the
     * column, sent per language like the rest.
     *
     * @var array<string, int>
     */
    private const TRANSLATABLE_LISTS = [
        'checklist' => 255,
        'fiqh_notes' => 500,
    ];

    /** Fields required in English, because English is the site's fallback. */
    private const REQUIRED_IN_ENGLISH = ['title', 'summary'];

    /** The route group and the controller's policy both gate this already. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseTranslations(
            array_keys(self::TRANSLATABLE),
            array_keys(self::TRANSLATABLE_LISTS),
        );
    }

    public function rules(): array
    {
        $rules = [
            // Unique, now that a step is one record rather than one per
            // language. Two rows sharing a step number used to be the way a
            // step was translated.
            'step_number' => [
                'required',
                'integer',
                'min:1',
                'max:255',
                Rule::unique('guide_steps')->ignore($this->route('guide_step')?->id),
            ],
            // Not translatable: this is the Arabic of the rite, the same words
            // whatever language the page is in.
            'dua_text' => ['nullable', 'string'],
            'video_url' => ['nullable', 'url', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:6144'],
            'is_published' => ['boolean'],
        ];

        foreach (self::TRANSLATABLE as $field => $max) {
            $rules = array_merge($rules, $this->translatedRules(
                $field,
                required: in_array($field, self::REQUIRED_IN_ENGLISH, true),
                max: $max > 0 ? $max : null,
            ));
        }

        foreach (self::TRANSLATABLE_LISTS as $field => $max) {
            $rules[$field] = ['nullable', 'array'];

            foreach (['en', 'dv'] as $locale) {
                $rules[$field.'.'.$locale] = ['nullable', 'array'];
                $rules[$field.'.'.$locale.'.*'] = ['string', 'max:'.$max];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'step_number.unique' => 'This step number is already taken.',
        ];
    }

    public function attributes(): array
    {
        $attributes = [];

        $labels = [
            'title' => 'title',
            'summary' => 'summary',
            'details' => 'details',
            'reference_text' => 'reference',
            'checklist' => 'checklist',
            'fiqh_notes' => 'fiqh notes',
        ];

        foreach ($labels as $field => $label) {
            $attributes[$field.'.en'] = $label.' (English)';
            $attributes[$field.'.dv'] = $label.' (Dhivehi)';
        }

        return $attributes;
    }
}
