@props([
    'name',
    'label',
    'value' => [],
    'type' => 'text',   // text | textarea | lines
    'rows' => 3,
    'required' => false,
    'help' => null,
])

{{--
    One field, both languages, side by side.

    The panel used to show the Dhivehi inputs only after you switched a
    language selector, which put them on a separate trip row that the public
    site then listed twice. Both boxes belong to the same trip: `name[en]` and
    `name[dv]` on the same record.
--}}
<fieldset {{ $attributes->merge(['class' => 'mb-6']) }}>
    <legend class="block text-sm font-medium text-gray-700 mb-2">
        {{ $label }}@if($required) <span aria-hidden="true">*</span>@endif
    </legend>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach(['en' => __('English'), 'dv' => __('Dhivehi')] as $locale => $language)
            @php($id = $name.'_'.$locale)
            @php($field = $name.'.'.$locale)
            <div>
                <label for="{{ $id }}" class="block text-xs uppercase tracking-wide text-gray-500 mb-1">
                    {{ $language }}@if($locale === 'dv') <span class="normal-case tracking-normal">{{ __('(optional)') }}</span>@endif
                </label>

                @if($type === 'lines')
                    {{-- A list field: one item per line. It used to be a column
                         of single-line inputs with an "add another" button,
                         beside a textarea of the same name that silently won
                         the tie and threw the inputs away. --}}
                    <textarea name="{{ $name }}[{{ $locale }}]" id="{{ $id }}" rows="{{ $rows }}"
                              @if($locale === 'dv') dir="rtl" lang="dv" @endif
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">{{ implode("\n", (array) (data_get($value, $locale) ?: [])) }}</textarea>
                @elseif($type === 'textarea')
                    <textarea name="{{ $name }}[{{ $locale }}]" id="{{ $id }}" rows="{{ $rows }}"
                              @if($required && $locale === 'en') required @endif
                              @if($locale === 'dv') dir="rtl" lang="dv" @endif
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">{{ data_get($value, $locale) }}</textarea>
                @else
                    <input type="text" name="{{ $name }}[{{ $locale }}]" id="{{ $id }}"
                           value="{{ data_get($value, $locale) }}"
                           @if($required && $locale === 'en') required @endif
                           @if($locale === 'dv') dir="rtl" lang="dv" @endif
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-wine-500 focus:border-transparent">
                @endif

                @error($field)
                    <p class="text-error text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>

    @if($help)
        <p class="text-sm text-gray-500 mt-1">{{ $help }}</p>
    @endif

    @error($name)
        <p class="text-error text-sm mt-1">{{ $message }}</p>
    @enderror
</fieldset>
