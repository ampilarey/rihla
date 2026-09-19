<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalisesTranslations;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One media item, with its text in both languages.
 *
 * Replaces two copies of the same rule list inside the controller's store()
 * and update(), which had already drifted: only store() required a file for a
 * photo.
 */
class MediaRequest extends FormRequest
{
    use NormalisesTranslations;

    /** The route group and the controller's policy both gate this already. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseTranslations(['title', 'caption']);
    }

    public function rules(): array
    {
        return array_merge([
            'trip_id' => 'nullable|exists:trips,id',
            'type' => 'required|in:photo,video',
            // Required only when creating a photo: an edit that changes the
            // caption should not demand the file again.
            'file_path' => ($this->isMethod('post') ? 'required_if:type,photo|' : '').'nullable|image|mimes:jpeg,png,webp|max:6144',
            'video_url' => 'required_if:type,video|nullable|url',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'boolean',
        ],
            // Neither is required: a photograph can stand without a caption,
            // and the gallery reads the title only when there is one.
            $this->translatedRules('title', required: false, max: 255),
            $this->translatedRules('caption', required: false),
        );
    }

    public function attributes(): array
    {
        return [
            'title.en' => 'title (English)',
            'title.dv' => 'title (Dhivehi)',
            'caption.en' => 'caption (English)',
            'caption.dv' => 'caption (Dhivehi)',
            'file_path' => 'image',
        ];
    }
}
