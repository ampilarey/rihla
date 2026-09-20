@props(['person'])

{{--
    One person. Everything on the card is something somebody entered; nothing
    is inferred or filled in to make the card look complete.

    No photograph is the ordinary case at first, and an initial on the brand
    wine reads better than a grey silhouette that says "no image" — and needs
    no request.
--}}
<article class="card flex h-full flex-col p-5 text-center">
    <div class="mx-auto mb-3">
        @if($person->photo_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($person->photo_path) }}"
                 alt=""
                 class="h-24 w-24 rounded-full object-cover"
                 loading="lazy" decoding="async">
        @else
            <img src="{{ \App\Support\InitialsAvatar::forName($person->name) }}"
                 alt=""
                 class="h-24 w-24 rounded-full">
        @endif
    </div>

    <h3 class="text-lg font-bold text-ink" dir="auto">{{ $person->name }}</h3>

    <p class="text-sm font-medium text-wine-600" dir="auto">
        {{ $person->title ?: $person->role_label }}
    </p>

    @if($person->bio)
        <p class="mt-3 text-sm text-brand-body" dir="auto">{{ $person->bio }}</p>
    @endif

    <dl class="mt-auto space-y-2 pt-4 text-sm">
        @if($person->language_list)
            <div>
                <dt class="text-ink-muted">{{ __('messages.Speaks') }}</dt>
                <dd class="font-medium text-ink" dir="auto">
                    {{ implode(', ', $person->language_list) }}
                </dd>
            </div>
        @endif

        {{-- Counted, not estimated. Absent when nobody has said. --}}
        @if($person->groups_led !== null)
            <div>
                <dt class="text-ink-muted">{{ __('messages.Groups led') }}</dt>
                <dd class="font-medium text-ink" dir="ltr">{{ $person->groups_led }}</dd>
            </div>
        @endif
    </dl>
</article>
