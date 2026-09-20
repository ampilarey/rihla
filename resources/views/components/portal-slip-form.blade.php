@props(['booking', 'balance'])

{{--
    Sending a transfer slip.

    The amount is the customer's word and is recorded as a claim, never as
    money received — Finance decides whether it arrived. Said out loud on the
    form, because a page that says "paid" the moment somebody uploads a
    picture is a page that will confirm bookings for free.

    Prefilled with the balance, which is what most people are sending, and
    editable because part-payments are normal here.
--}}
<form method="POST"
      action="{{ route('portal.payments.store', ['locale' => app()->getLocale()]) }}"
      enctype="multipart/form-data"
      class="mt-4 space-y-3 rounded-xl border border-cream-deep p-4">
    @csrf

    <h3 dir="auto" class="font-semibold text-ink">{{ __('messages.Send us your transfer slip') }}</h3>

    <div>
        <label dir="auto" for="portal-amount" class="mb-1 block text-sm text-ink-muted">
            {{ __('messages.How much did you send?') }}
        </label>
        <input id="portal-amount" name="amount" type="number" min="1" inputmode="numeric" required
               value="{{ old('amount', max(1, $balance->major())) }}"
               class="w-full rounded-xl border border-cream-deep px-3 py-2" dir="ltr">
        <x-input-error :messages="$errors->get('amount')" class="mt-1" />
    </div>

    <div>
        <label dir="auto" for="portal-paid-at" class="mb-1 block text-sm text-ink-muted">
            {{ __('messages.When did you send it?') }}
        </label>
        <input id="portal-paid-at" name="paid_at" type="date" max="{{ now()->toDateString() }}"
               value="{{ old('paid_at') }}"
               class="w-full rounded-xl border border-cream-deep px-3 py-2" dir="ltr">
        <x-input-error :messages="$errors->get('paid_at')" class="mt-1" />
    </div>

    <div>
        <label dir="auto" for="portal-payer" class="mb-1 block text-sm text-ink-muted">
            {{ __('messages.Whose account did it come from?') }}
        </label>
        <input id="portal-payer" name="payer_name" type="text" maxlength="255"
               value="{{ old('payer_name') }}"
               class="w-full rounded-xl border border-cream-deep px-3 py-2" dir="auto">
        <x-input-error :messages="$errors->get('payer_name')" class="mt-1" />
    </div>

    <div>
        <label dir="auto" for="portal-slip" class="mb-1 block text-sm text-ink-muted">
            {{ __('messages.The slip') }}
        </label>
        <input id="portal-slip" name="slip" type="file" required
               accept="{{ implode(',', (array) config('payments.slips.mime_types')) }}"
               class="w-full rounded-xl border border-cream-deep px-3 py-2">
        <x-input-error :messages="$errors->get('slip')" class="mt-1" />
    </div>

    <button type="submit" class="btn-primary w-full" dir="auto">{{ __('messages.Send it') }}</button>

    <p dir="auto" class="text-sm text-ink-muted">
        {{ __('messages.We will check it against our account and confirm. Nothing is marked as paid until we have.') }}
    </p>
</form>
