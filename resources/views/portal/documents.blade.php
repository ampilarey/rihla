@extends('layouts.app')

@section('title', __('messages.Documents'))

@section('content')
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">

        <x-portal-nav current="documents" />

        <section class="card mb-6">
            <h1 dir="auto" class="mb-2 text-2xl font-bold text-ink">{{ __('messages.Documents') }}</h1>

            {{--
                What is on file and what state it is in — never the file
                itself. A portal link is sent over WhatsApp and will be
                forwarded into a family group chat; "your passport is
                verified" belongs there and a passport scan does not.
            --}}
            <p dir="auto" class="mb-4 text-sm text-ink-muted">
                {{ __('messages.For your safety we do not show the files themselves here. Ask us if you need a copy back.') }}
            </p>

            <ul class="divide-y divide-cream-deep">
                @foreach($travellers as $line)
                    @php($traveller = $line->traveller)
                    @php($theirs = $documents[$traveller->id] ?? collect())
                    @php($passport = $theirs->firstWhere('type', \App\Models\Document::PASSPORT))
                    @php($visa = $visas[$traveller->id] ?? null)
                    @php($permit = $permits[$traveller->id] ?? null)

                    <li class="py-4">
                        <span dir="auto" class="block font-medium text-ink">{{ $traveller->full_name }}</span>

                        <dl class="mt-2 space-y-1 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt dir="auto" class="text-ink-muted">{{ __('messages.Passport') }}</dt>
                                <dd dir="auto" class="text-end font-medium text-ink">
                                    @if($passport === null)
                                        {{ __('messages.Not sent yet') }}
                                    @else
                                        {{ \App\Support\PortalWords::documentStatus($passport->status) }}
                                        @if($passport->status === \App\Models\Document::REJECTED && $passport->rejection_reason)
                                            <span class="block font-normal text-ink-muted">{{ $passport->rejection_reason }}</span>
                                        @endif
                                    @endif
                                </dd>
                            </div>

                            <div class="flex justify-between gap-4">
                                <dt dir="auto" class="text-ink-muted">{{ __('messages.Visa') }}</dt>
                                <dd dir="auto" class="text-end font-medium text-ink">
                                    {{ \App\Support\PortalWords::visaStatus($visa?->status) }}
                                </dd>
                            </div>

                            <div class="flex justify-between gap-4">
                                <dt dir="auto" class="text-ink-muted">{{ __('messages.Umrah permit') }}</dt>
                                <dd dir="auto" class="text-end font-medium text-ink">
                                    {{ \App\Support\PortalWords::permitStatus($permit?->status) }}
                                </dd>
                            </div>
                        </dl>
                    </li>
                @endforeach
            </ul>
        </section>

        @if(config('portal.uploads.enabled'))
            <section class="card mb-6">
                <h2 dir="auto" class="mb-4 text-lg font-semibold text-ink">{{ __('messages.Send us a passport') }}</h2>

                <form method="POST"
                      action="{{ route('portal.documents.store', ['locale' => app()->getLocale()]) }}"
                      enctype="multipart/form-data"
                      class="space-y-3">
                    @csrf

                    <div>
                        <label dir="auto" for="portal-traveller" class="mb-1 block text-sm text-ink-muted">
                            {{ __('messages.Whose passport is this?') }}
                        </label>
                        <select id="portal-traveller" name="traveller_id" required
                                class="w-full rounded-xl border border-cream-deep px-3 py-2" dir="auto">
                            @foreach($travellers as $line)
                                <option value="{{ $line->traveller_id }}">{{ $line->traveller->full_name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('traveller_id')" class="mt-1" />
                    </div>

                    <div>
                        <label dir="auto" for="portal-expires" class="mb-1 block text-sm text-ink-muted">
                            {{ __('messages.When does it expire?') }}
                        </label>
                        <input id="portal-expires" name="expires_at" type="date"
                               value="{{ old('expires_at') }}"
                               class="w-full rounded-xl border border-cream-deep px-3 py-2" dir="ltr">
                        {{-- Said plainly rather than enforced in the form: the
                             rule is Saudi Arabia's, it is in configuration
                             because it changes, and a checkout that silently
                             rejects a passport is worse than one that warns. --}}
                        <p dir="auto" class="mt-1 text-sm text-ink-muted">
                            {{ __('messages.It needs to be valid for at least :months months after you come home.', [
                                'months' => config('documents.passport_validity_months'),
                            ]) }}
                        </p>
                        <x-input-error :messages="$errors->get('expires_at')" class="mt-1" />
                    </div>

                    <div>
                        <label dir="auto" for="portal-file" class="mb-1 block text-sm text-ink-muted">
                            {{ __('messages.A photo or a scan of the page with your picture on it') }}
                        </label>
                        <input id="portal-file" name="file" type="file" required
                               accept="{{ implode(',', (array) config('documents.mime_types')) }}"
                               class="w-full rounded-xl border border-cream-deep px-3 py-2">
                        <x-input-error :messages="$errors->get('file')" class="mt-1" />
                    </div>

                    <button type="submit" class="btn-primary w-full" dir="auto">{{ __('messages.Send it') }}</button>
                </form>
            </section>
        @endif

        <x-portal-footer />
    </div>
@endsection
