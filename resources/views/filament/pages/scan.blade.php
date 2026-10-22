<x-filament-panels::page>
    {{-- Filament components only: Tailwind utilities do nothing in /staff. --}}
    <x-filament::section>
        <x-slot name="heading">Find the traveller</x-slot>
        <x-slot name="description">Point the phone's camera at the QR and it opens here, or type the serial printed under it.</x-slot>
        <form wire:submit="lookup" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center;">
            <x-filament::input.wrapper style="flex:1 1 12rem;">
                <x-filament::input type="text" wire:model="code" placeholder="RIH-C-000012" autocomplete="off" autocapitalize="characters" aria-label="Serial or QR link" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit">Find</x-filament::button>
            <x-filament::button type="button" color="gray" x-data x-show="'BarcodeDetector' in window" x-on:click="$dispatch('rihla-scan-start')">Camera</x-filament::button>
        </form>

        <div x-data="rihlaScanner($wire)" x-on:rihla-scan-start.window="start()" x-show="open" x-cloak style="margin-top:1rem;">
            <video x-ref="video" playsinline muted style="width:100%; max-height:60vh; border-radius:.5rem; background:var(--gray-900);"></video>
            <div style="display:flex; gap:.5rem; margin-top:.5rem; align-items:center;">
                <x-filament::button type="button" color="gray" x-on:click="stop()">Stop camera</x-filament::button>
                <span class="fi-color-gray" x-text="status" style="font-size:.875rem;"></span>
            </div>
        </div>
    </x-filament::section>

    @if ($member)
        <x-filament::section>
            <x-slot name="heading">{{ $member->traveller->full_name }}</x-slot>
            <x-slot name="description">{{ $card->kindLabel() }} {{ $card->serial }} · {{ $booking->departure->package->title ?? 'Departure' }} · {{ $booking->departure->date_start->format('j M Y') }}</x-slot>
            <dl style="display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin:0;">
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Bus</dt><dd style="font-weight:600; font-size:1.25rem; margin:0;">{{ $member->busGroup?->name ?? '—' }}</dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">On the bus</dt><dd style="font-weight:600; font-size:1.25rem; margin:0;">{{ $member->isCheckedIn() ? 'Yes · '.$member->checked_in_at->format('H:i') : 'Not yet' }}</dd></div>
                @foreach ($rooms as $room)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">{{ $room['city'] }} room</dt><dd style="font-weight:600; margin:0;">{{ $room['room'] ?? '—' }}</dd></div>
                @endforeach
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Booked by</dt><dd style="margin:0;">{{ $booking->customer->name }}</dd></div>
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Booking</dt><dd style="margin:0;">{{ $booking->reference }} · {{ ucfirst($booking->status) }}</dd></div>
                @if ($canSeeMoney)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">Money</dt><dd style="margin:0;">{{ $balance }}</dd></div>
                @endif
                <div><dt class="fi-color-gray" style="font-size:.75rem;">Passport</dt><dd style="margin:0;">{{ $passport }}{{ $member->traveller->passport_expiry ? ' · expires '.$member->traveller->passport_expiry->format('j M Y') : '' }}</dd></div>
                @if ($family)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">Family head</dt><dd style="margin:0;">{{ $family->isHead($member) ? 'This person' : $family->head->traveller->full_name }}</dd></div>
                @endif
                @if ($phone)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">Phone ({{ $phoneSource }})</dt><dd style="margin:0;"><a href="{{ \App\Support\Contact::telUrlFor($phone) }}">{{ $phone }}</a></dd></div>
                @endif
                @if ($member->traveller->medical_notes)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">Needs</dt><dd style="margin:0;">{{ $member->traveller->medical_notes }}</dd></div>
                @endif
                @if ($member->traveller->emergency_contact_phone)
                    <div><dt class="fi-color-gray" style="font-size:.75rem;">Emergency contact</dt><dd style="margin:0;">{{ trim(($member->traveller->emergency_contact_name ? $member->traveller->emergency_contact_name.' ' : '').$member->traveller->emergency_contact_phone) }}</dd></div>
                @endif
            </dl>
            <div style="display:flex; flex-wrap:wrap; gap:.5rem; margin-top:1rem;">
                @if ($canCheckIn && $member->busGroup)
                    <x-filament::button wire:click="checkIn" :color="$member->isCheckedIn() ? 'gray' : 'success'">{{ $member->isCheckedIn() ? 'Undo boarding' : 'Check in to '.$member->busGroup->name }}</x-filament::button>
                @endif
                @if ($canOpenBooking)
                    <x-filament::button tag="a" color="gray" href="{{ \App\Filament\Resources\Bookings\BookingResource::getUrl('view', ['record' => $booking]) }}">Booking</x-filament::button>
                @endif
                <x-filament::button wire:click="clear" color="gray">Next</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    @push('scripts')
    {{-- The nonce is what the public site's policy checks; the panel's
         policy allows inline scripts, and the guard test wants it anyway. --}}
    <script nonce="{{ \App\Support\Csp::nonce() }}">
        // The browser's own QR reader (Chrome on Android). Phones without it use the camera app: the QR is a link to this page.
        document.addEventListener('alpine:init', () => {
            Alpine.data('rihlaScanner', ($wire) => ({
                open: false, status: '', stream: null, timer: null,
                async start() {
                    if (!('BarcodeDetector' in window)) { return; }
                    this.open = true; this.status = 'Looking for a QR…';
                    try {
                        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                        this.$refs.video.srcObject = this.stream;
                        await this.$refs.video.play();
                        const detector = new BarcodeDetector({ formats: ['qr_code'] });
                        this.timer = setInterval(async () => {
                            try {
                                const codes = await detector.detect(this.$refs.video);
                                if (codes.length > 0) {
                                    const value = codes[0].rawValue;
                                    this.stop();
                                    await $wire.set('code', value);
                                    await $wire.lookup();
                                }
                            } catch (e) { this.status = 'Could not read: ' + e.message; }
                        }, 400);
                    } catch (e) { this.status = 'Camera not available: ' + e.message; this.open = false; }
                },
                stop() {
                    if (this.timer) { clearInterval(this.timer); this.timer = null; }
                    if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
                    this.open = false; this.status = '';
                },
            }));
        });
    </script>
    @endpush
</x-filament-panels::page>
