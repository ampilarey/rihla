<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesTranslations;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWhySectionRequest extends FormRequest
{
    use NormalisesTranslations;

    /**
     * Determine if the user is authorized to make this request.
     */
    /**
     * The route group and the controller's policy both gate this already;
     * returning true here defers to them rather than adding a third, weaker
     * answer that could drift out of step with the other two.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseTranslations(['title', 'subtitle', 'primary_cta_text', 'secondary_cta_text']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'primary_cta_url' => 'nullable|url|max:500',
            'secondary_cta_url' => 'nullable|url|max:500',
            'title_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'subtitle_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'primary_cta_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'primary_cta_text_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_cta_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_cta_text_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
        ],
            $this->translatedRules('title', required: true, max: 255),
            $this->translatedRules('subtitle', required: false, max: 1000),
            $this->translatedRules('primary_cta_text', required: false, max: 255),
            $this->translatedRules('secondary_cta_text', required: false, max: 255),
        );
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'title.en' => 'title (English)',
            'title.dv' => 'title (Dhivehi)',
            'subtitle.en' => 'subtitle (English)',
            'subtitle.dv' => 'subtitle (Dhivehi)',
            'image' => 'image',
            'primary_cta_text.en' => 'primary CTA text (English)',
            'primary_cta_text.dv' => 'primary CTA text (Dhivehi)',
            'primary_cta_url' => 'primary CTA URL',
            'secondary_cta_text.en' => 'secondary CTA text (English)',
            'secondary_cta_text.dv' => 'secondary CTA text (Dhivehi)',
            'secondary_cta_url' => 'secondary CTA URL',
            'title_color' => 'title color',
            'subtitle_color' => 'subtitle color',
            'primary_cta_bg_color' => 'primary CTA background color',
            'primary_cta_text_color' => 'primary CTA text color',
            'secondary_cta_bg_color' => 'secondary CTA background color',
            'secondary_cta_text_color' => 'secondary CTA text color',
        ];
    }
}
