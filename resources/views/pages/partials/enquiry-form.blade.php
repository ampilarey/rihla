{{-- The enquiry form, on rihla.mv's contact page and on every company's
     (companies plan, Phase 4a). It posts to the same route either way; the
     enquiry lands in whichever company the address belongs to. --}}
<form method="POST" action="{{ route('enquiries.store') }}" class="space-y-3">
    @csrf

    <div>
        <label dir="auto" for="enquiry-name" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Your name') }}</label>
        <input id="enquiry-name" name="name" type="text" required maxlength="255"
               value="{{ old('name') }}" dir="auto"
               class="w-full rounded-xl border border-gray-500 px-3 py-2">
        <x-input-error :messages="$errors->get('name')" class="mt-1" />
    </div>

    <div>
        <label dir="auto" for="enquiry-phone" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Phone') }}</label>
        <input id="enquiry-phone" name="phone" type="tel" maxlength="40" inputmode="tel"
               value="{{ old('phone') }}" dir="ltr"
               class="w-full rounded-xl border border-gray-500 px-3 py-2">
        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
    </div>

    <div>
        <label dir="auto" for="enquiry-email" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Email') }}</label>
        <input id="enquiry-email" name="email" type="email" maxlength="255"
               value="{{ old('email') }}" dir="ltr"
               class="w-full rounded-xl border border-gray-500 px-3 py-2">
        <x-input-error :messages="$errors->get('email')" class="mt-1" />
    </div>

    @if($packages->isNotEmpty())
        <div>
            <label dir="auto" for="enquiry-package" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Which package?') }}</label>
            <select id="enquiry-package" name="package_id" dir="auto"
                    class="w-full rounded-xl border border-gray-500 px-3 py-2">
                <option value="">{{ __('messages.Not sure yet') }}</option>
                @foreach($packages as $package)
                    <option value="{{ $package->id }}" @selected(old('package_id') == $package->id)>
                        {{ $package->title }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif

    <div>
        <label dir="auto" for="enquiry-party" class="mb-1 block text-sm text-ink-muted">{{ __('messages.How many of you?') }}</label>
        <input id="enquiry-party" name="party_size" type="number" min="1" max="60" inputmode="numeric"
               value="{{ old('party_size') }}" dir="ltr"
               class="w-full rounded-xl border border-gray-500 px-3 py-2">
        <x-input-error :messages="$errors->get('party_size')" class="mt-1" />
    </div>

    <div>
        <label dir="auto" for="enquiry-message" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Anything else?') }}</label>
        <textarea id="enquiry-message" name="message" rows="3" maxlength="2000" dir="auto"
                  class="w-full rounded-xl border border-gray-500 px-3 py-2">{{ old('message') }}</textarea>
        <x-input-error :messages="$errors->get('message')" class="mt-1" />
    </div>

    {{-- Not shown to anybody, and not announced to a
         screen reader either: aria-hidden and
         tabindex="-1" keep it out of the reading order,
         so it catches a bot without tripping a person
         using one. --}}
    <div aria-hidden="true" style="position:absolute;left:-9999px;">
        <label for="enquiry-website">{{ __('messages.Leave this empty') }}</label>
        <input id="enquiry-website" name="website" type="text" tabindex="-1" autocomplete="off">
    </div>

    <button type="submit" dir="auto" class="btn-primary w-full">{{ __('messages.Send') }}</button>
</form>
