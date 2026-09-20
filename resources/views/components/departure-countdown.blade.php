@props(['departure'])

{{--
    Days until departure. Cheap, and the plan rates it high for the effort.

    Says nothing once the date has passed rather than counting upwards, and
    says "departs today" rather than "0 days", because "0 days to go" reads
    like a bug.
--}}
@php($days = $departure->days_until)

@if($days !== null && $days >= 0)
    {{-- dir="auto" rather than inheriting the page direction. On a Dhivehi
         page an untranslated "33 days to go" inherited RTL and rendered as
         "days to go 33" — the digits are bidi-neutral, so they jumped to the
         wrong end of the phrase. auto takes the direction from the first
         strong character, which is correct whether this string has been
         translated into Thaana or is still falling back to English. --}}
    <span dir="auto" {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-sm font-medium text-wine-600']) }}>
        <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <circle cx="12" cy="12" r="9" />
            <path stroke-linecap="round" d="M12 7v5l3 2" />
        </svg>
        @if($days === 0)
            {{ __('messages.Departs today') }}
        @else
            {{ trans_choice('{1}:count day to go|[2,*]:count days to go', $days, ['count' => $days]) }}
        @endif
    </span>
@endif
