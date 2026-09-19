<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesTranslations;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpsertWhyFeatureRequest extends FormRequest
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
        $this->normaliseTranslations(['title', 'text', 'link_text']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'why_section_id' => 'required|exists:why_sections,id',
            'icon' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'link_url' => 'nullable|url|max:500',
            'background_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ],
            $this->translatedRules('title', required: true, max: 255),
            $this->translatedRules('text', required: false, max: 1000),
            $this->translatedRules('link_text', required: false, max: 100),
        );
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'why_section_id' => 'section',
            'icon' => 'icon',
            'title.en' => 'title (English)',
            'title.dv' => 'title (Dhivehi)',
            'text.en' => 'text (English)',
            'text.dv' => 'text (Dhivehi)',
            'image' => 'image',
            'link_url' => 'link URL',
            'link_text.en' => 'link text (English)',
            'link_text.dv' => 'link text (Dhivehi)',
            'background_color' => 'background color',
            'sort_order' => 'sort order',
        ];
    }
}
