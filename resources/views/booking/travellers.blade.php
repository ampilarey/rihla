@extends('layouts.app')

@section('title', __('messages.Who is travelling?'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">
        <h1 dir="auto" class="mb-2 text-3xl font-bold text-ink">{{ __('messages.Who is travelling?') }}</h1>
        <p dir="auto" class="mb-6 text-brand-body">
            {{ __('messages.Names must match the passport each traveller will use.') }}
        </p>

        <x-hold-timer :hold="$hold" class="mb-6" />

        <form method="POST" action="{{ route('booking.travellers.store') }}" class="space-y-6">
            @csrf

            <section class="card space-y-4">
                <h2 dir="auto" class="text-lg font-semibold text-ink">{{ __('messages.Who we should contact') }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label dir="auto" for="contact_name" class="mb-1 block text-sm font-medium text-ink">
                            {{ __('messages.Full name') }}
                        </label>
                        <input id="contact_name" name="contact_name" type="text" autocomplete="name"
                               value="{{ old('contact_name') }}" required dir="auto"
                               class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                        <x-input-error :messages="$errors->get('contact_name')" class="mt-2" />
                    </div>

                    <div>
                        <label dir="auto" for="contact_phone" class="mb-1 block text-sm font-medium text-ink">
                            {{ __('messages.Phone') }}
                        </label>
                        <input id="contact_phone" name="contact_phone" type="tel" autocomplete="tel" dir="ltr"
                               value="{{ old('contact_phone') }}" required
                               class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                        <x-input-error :messages="$errors->get('contact_phone')" class="mt-2" />
                    </div>

                    <div>
                        <label dir="auto" for="contact_email" class="mb-1 block text-sm font-medium text-ink">
                            {{ __('messages.Email (optional)') }}
                        </label>
                        <input id="contact_email" name="contact_email" type="email" autocomplete="email" dir="ltr"
                               value="{{ old('contact_email') }}"
                               class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                        <x-input-error :messages="$errors->get('contact_email')" class="mt-2" />
                    </div>
                </div>
            </section>

            <x-input-error :messages="$errors->get('travellers')" />

            @for($i = 0; $i < $hold->seats; $i++)
                <section class="card space-y-4">
                    <h2 dir="auto" class="text-lg font-semibold text-ink">
                        {{ __('messages.Traveller :number', ['number' => $i + 1]) }}
                    </h2>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label dir="auto" for="traveller-{{ $i }}-name" class="mb-1 block text-sm font-medium text-ink">
                                {{ __('messages.Full name as in passport') }}
                            </label>
                            <input id="traveller-{{ $i }}-name" name="travellers[{{ $i }}][full_name]" type="text"
                                   value="{{ old("travellers.{$i}.full_name") }}" required dir="auto"
                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                            <x-input-error :messages="$errors->get('travellers.'.$i.'.full_name')" class="mt-2" />
                        </div>

                        <div>
                            <label dir="auto" for="traveller-{{ $i }}-dob" class="mb-1 block text-sm font-medium text-ink">
                                {{ __('messages.Date of birth') }}
                            </label>
                            <input id="traveller-{{ $i }}-dob" name="travellers[{{ $i }}][date_of_birth]" type="date"
                                   value="{{ old("travellers.{$i}.date_of_birth") }}" dir="ltr"
                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                            <x-input-error :messages="$errors->get('travellers.'.$i.'.date_of_birth')" class="mt-2" />
                        </div>

                        <div>
                            <label dir="auto" for="traveller-{{ $i }}-gender" class="mb-1 block text-sm font-medium text-ink">
                                {{ __('messages.Gender') }}
                            </label>
                            {{-- Asked because the rules need it — rooms are
                                 allocated single-sex — not for demographics. --}}
                            <select id="traveller-{{ $i }}-gender" name="travellers[{{ $i }}][gender]"
                                    class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                                <option value="">{{ __('messages.Prefer not to say') }}</option>
                                <option value="male" @selected(old("travellers.{$i}.gender") === 'male')>{{ __('messages.Male') }}</option>
                                <option value="female" @selected(old("travellers.{$i}.gender") === 'female')>{{ __('messages.Female') }}</option>
                            </select>
                        </div>

                        <div>
                            <label dir="auto" for="traveller-{{ $i }}-passport" class="mb-1 block text-sm font-medium text-ink">
                                {{ __('messages.Passport number (optional)') }}
                            </label>
                            <input id="traveller-{{ $i }}-passport" name="travellers[{{ $i }}][passport_number]" type="text"
                                   value="{{ old("travellers.{$i}.passport_number") }}" dir="ltr"
                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                        </div>

                        <div>
                            <label dir="auto" for="traveller-{{ $i }}-expiry" class="mb-1 block text-sm font-medium text-ink">
                                {{ __('messages.Passport expiry (optional)') }}
                            </label>
                            <input id="traveller-{{ $i }}-expiry" name="travellers[{{ $i }}][passport_expiry]" type="date"
                                   value="{{ old("travellers.{$i}.passport_expiry") }}" dir="ltr"
                                   class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                        </div>
                    </div>
                </section>
            @endfor

            <p dir="auto" class="text-sm text-ink-muted">
                {{ __('messages.Passport details can be added later if you do not have them to hand.') }}
            </p>

            <button type="submit" class="btn-primary w-full">{{ __('messages.Continue to review') }}</button>
        </form>
    </div>
@endsection
