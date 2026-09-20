@props(['departure'])

{{--
    "What will this actually cost us?" — for a party, not one person.

    Umrah is booked by families, and the price that matters is the whole
    party's: two adults in a double is a different number from four in a
    quad, and working it out from a price list is exactly the friction this
    removes. The plan rates it a converter of the "can I afford it"
    hesitation.

    **Instalments are deliberately absent.** The plan pairs this with an
    instalment schedule, and Rihla's terms — whether the deposit is a
    percentage or a flat sum, how many instalments, when they fall due — are
    business policy nobody has stated. Inventing a plausible one and printing
    it next to a real price is how this site ended up advertising four social
    accounts that did not exist and a YouTube playlist that returned 404. The
    total is arithmetic and is shown; the schedule is policy and is asked
    for.

    Arithmetic in the browser, from prices rendered by the server: no request
    per keystroke, and it degrades to a readable price list with scripting
    off.
--}}
@php($tiers = $departure->priceTiers)

@if($tiers->isNotEmpty())
    @php($tierData = $tiers->map(fn ($tier) => [
        'occupancy' => $tier->occupancy,
        'paxType' => $tier->pax_type,
        'minor' => $tier->amount_minor,
        'label' => __('messages.'.ucfirst($tier->occupancy).' room'),
        'formatted' => $tier->formatted,
    ])->values())

    <div class="card p-5"
         x-data="{
            tiers: {{ Js::from($tierData) }},
            occupancy: {{ Js::from($tiers->first()->occupancy) }},
            travellers: 1,
            get tier() {
                return this.tiers.find(t => t.occupancy === this.occupancy) ?? this.tiers[0];
            },
            get totalMinor() {
                return this.tier.minor * Math.max(1, Number(this.travellers) || 1);
            },
            get total() {
                {{-- Whole rufiyaa when the amount is whole, matching
                     App\Support\Money::format() on the server. --}}
                const major = this.totalMinor / 100;
                return 'MVR ' + major.toLocaleString('en-US', {
                    minimumFractionDigits: this.totalMinor % 100 === 0 ? 0 : 2,
                    maximumFractionDigits: this.totalMinor % 100 === 0 ? 0 : 2,
                });
            },
         }">
        <h3 class="mb-4 text-lg font-bold text-ink">{{ __('messages.What will it cost?') }}</h3>

        <div class="space-y-4">
            <div>
                <label for="occupancy-{{ $departure->id }}" class="mb-1 block text-sm font-medium text-ink">
                    {{ __('messages.Room') }}
                </label>
                <select id="occupancy-{{ $departure->id }}"
                        x-model="occupancy"
                        class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500">
                    @foreach($tiers as $tier)
                        <option value="{{ $tier->occupancy }}">
                            {{ __('messages.'.ucfirst($tier->occupancy).' room') }} — {{ $tier->formatted }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="travellers-{{ $departure->id }}" class="mb-1 block text-sm font-medium text-ink">
                    {{ __('messages.Travellers') }}
                </label>
                <input id="travellers-{{ $departure->id }}"
                       type="number" min="1" max="20" step="1"
                       x-model.number="travellers"
                       class="w-full rounded-lg border-cream-deep text-ink focus:border-wine-500 focus:ring-wine-500"
                       value="1">
            </div>

            {{-- aria-live, because the number changes without the page
                 reloading and a screen reader is otherwise told nothing. --}}
            <div class="border-t border-cream-deep pt-4">
                <p class="text-sm text-ink-muted">{{ __('messages.Total for your party') }}</p>
                <p class="text-2xl font-bold text-wine-600" dir="ltr" aria-live="polite" x-text="total">
                    {{ $tiers->first()->formatted }}
                </p>
            </div>

            {{-- Policy, not arithmetic. Asked for rather than guessed. --}}
            <p class="text-sm text-brand-body">
                {{ __('messages.Ask us about paying in instalments.') }}
            </p>

            <a href="{{ \App\Support\Contact::whatsappUrl(
                    __('messages.Hello, I would like to ask about :package departing :date.', [
                        'package' => $departure->package->getTranslation('title', 'en'),
                        'date' => $departure->date_start->format('j M Y'),
                    ]),
                ) }}"
               class="btn-primary w-full" target="_blank" rel="noopener noreferrer">
                {{ __('messages.cta_whatsapp') }}
            </a>
        </div>
    </div>
@endif
