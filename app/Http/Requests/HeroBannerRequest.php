<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesTranslations;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One hero banner, in both languages.
 *
 * This replaces two identical copies of the same rule list inside the
 * controller's store() and update(), built by hand with `Validator::make` and
 * checked with `$validator->fails()` — so a rule added to one was silently
 * absent from the other.
 */
class HeroBannerRequest extends FormRequest
{
    use NormalisesTranslations;

    /** The route group and the controller's policy both gate this already. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseTranslations(['title', 'subtitle', 'primary_cta_text', 'secondary_cta_text']);
    }

    public function rules(): array
    {
        return array_merge([
            // Not translated: a button points at the same page in either
            // language, and the locale is a path prefix the router adds.
            'primary_cta_url' => 'nullable|string|max:255',
            'secondary_cta_url' => 'nullable|string|max:255',
            'overlay_opacity' => 'integer|between:0,100',
            'heading_color' => 'nullable|string|max:20',
            'heading_size' => 'nullable|string|max:20',
            'heading_weight' => 'nullable|string|max:20',
            'subheading_color' => 'nullable|string|max:20',
            'subheading_size' => 'nullable|string|max:20',
            'subheading_weight' => 'nullable|string|max:20',
            'primary_cta_bg_color' => 'nullable|string|max:20',
            'primary_cta_text_color' => 'nullable|string|max:20',
            'primary_cta_size' => 'nullable|string|max:20',
            'primary_cta_radius' => 'nullable|string|max:20',
            'secondary_cta_bg_color' => 'nullable|string|max:20',
            'secondary_cta_text_color' => 'nullable|string|max:20',
            'secondary_cta_size' => 'nullable|string|max:20',
            'secondary_cta_radius' => 'nullable|string|max:20',
            'sort_order' => 'integer|min:0',
            // Present when ticked, absent when not; the controller reads the
            // checkbox itself.
            'is_active' => 'nullable',
            'start_at' => 'nullable|date',
            'end_at' => 'nullable|date|after:start_at',
            'image' => 'nullable|image|mimes:jpeg,png,webp|max:8192',
        ],
            $this->translatedRules('title', required: true, max: 120),
            $this->translatedRules('subtitle', required: false, max: 200),
            $this->translatedRules('primary_cta_text', required: false, max: 60),
            $this->translatedRules('secondary_cta_text', required: false, max: 60),
        );
    }

    public function attributes(): array
    {
        return [
            'title.en' => 'title (English)',
            'title.dv' => 'title (Dhivehi)',
            'subtitle.en' => 'subtitle (English)',
            'subtitle.dv' => 'subtitle (Dhivehi)',
            'primary_cta_text.en' => 'primary button text (English)',
            'primary_cta_text.dv' => 'primary button text (Dhivehi)',
            'secondary_cta_text.en' => 'secondary button text (English)',
            'secondary_cta_text.dv' => 'secondary button text (Dhivehi)',
        ];
    }
}
