<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWhySectionRequest extends FormRequest
{
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locale' => 'required|string|in:en,dv',
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:1000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'primary_cta_text' => 'nullable|string|max:255',
            'primary_cta_url' => 'nullable|url|max:500',
            'secondary_cta_text' => 'nullable|string|max:255',
            'secondary_cta_url' => 'nullable|url|max:500',
            'title_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'subtitle_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'primary_cta_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'primary_cta_text_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_cta_bg_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'secondary_cta_text_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'title' => 'title',
            'subtitle' => 'subtitle',
            'image' => 'image',
            'primary_cta_text' => 'primary CTA text',
            'primary_cta_url' => 'primary CTA URL',
            'secondary_cta_text' => 'secondary CTA text',
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
