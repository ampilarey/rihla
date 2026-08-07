<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertWhyFeatureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'why_section_id' => 'required|exists:why_sections,id',
            'icon' => 'nullable|string|max:255',
            'title' => 'required|string|max:255',
            'text' => 'nullable|string|max:1000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'link_url' => 'nullable|url|max:500',
            'link_text' => 'nullable|string|max:100',
            'background_color' => 'nullable|string|max:7|regex:/^#[0-9A-Fa-f]{6}$/',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'why_section_id' => 'section',
            'icon' => 'icon',
            'title' => 'title',
            'text' => 'text',
            'image' => 'image',
            'link_url' => 'link URL',
            'link_text' => 'link text',
            'background_color' => 'background color',
            'sort_order' => 'sort order',
        ];
    }
}
