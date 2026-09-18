<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuideStepRequest extends FormRequest
{
    /**
     * The route group and the controller's policy both gate this already;
     * returning true here defers to them rather than adding a third, weaker
     * answer that could drift out of step with the other two.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $guideStepId = $this->route('guide_step')?->id;

        return [
            'step_number' => [
                'required',
                'integer',
                'min:1',
                'max:255',
                Rule::unique('guide_steps')->ignore($guideStepId),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'photo_path' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'is_published' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'step_number.unique' => 'This step number is already taken.',
            'step_number.min' => 'The step number must be at least 1.',
            'step_number.max' => 'The step number cannot exceed 255.',
            'photo_path.max' => 'The photo file size must not exceed 2MB.',
        ];
    }
}
