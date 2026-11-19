@extends('layouts.app')

@section('title', __('messages.Your agreement'))

@section('content')
    {{--
        The booking agreement (companies plan, Phase 3h, part 3, from Hijaz):
        the company's own terms, signed on screen or as a scanned paper copy.
        The page does not exist until the company has written its terms.
    --}}
    <div class="container mx-auto max-w-3xl px-4 section-y-tight">
        <x-portal-nav current="agreement" />

        @if(session('portal_notice'))
            <p dir="auto" class="card mb-6 font-medium text-ink">{{ session('portal_notice') }}</p>
        @endif

        <section class="card mb-6">
            <h1 dir="auto" class="mb-4 text-2xl font-bold text-ink">{{ __('messages.Your agreement') }}</h1>

            <div dir="auto" class="mb-4 whitespace-pre-line text-ink">{{ $agreement->isSigned() ? $agreement->wording : $terms }}</div>

            @if($agreement->isSigned())
                <p dir="auto" class="mb-4 font-medium text-ink">{{ $agreement->statusWords() }}</p>
                <a dir="auto" class="inline-block py-1 text-sm font-medium text-wine-700 underline" href="{{ route('portal.agreement.pdf') }}">
                    {{ __('messages.Download the signed agreement') }}
                </a>
            @endif
        </section>

        @unless($agreement->isSigned())
            <section class="card mb-6"
                     x-data="{
                         drawing: false, drawn: false,
                         ctx() { return this.$refs.pad.getContext('2d') },
                         point(e) { const r = this.$refs.pad.getBoundingClientRect(); const t = e.touches ? e.touches[0] : e; return [(t.clientX - r.left) * this.$refs.pad.width / r.width, (t.clientY - r.top) * this.$refs.pad.height / r.height] },
                         start(e) { this.drawing = true; const [x, y] = this.point(e); const c = this.ctx(); c.lineWidth = 3; c.lineCap = 'round'; c.beginPath(); c.moveTo(x, y) },
                         move(e) { if (! this.drawing) return; const [x, y] = this.point(e); const c = this.ctx(); c.lineTo(x, y); c.stroke(); this.drawn = true },
                         stop() { this.drawing = false; if (this.drawn) this.$refs.signature.value = this.$refs.pad.toDataURL('image/png') },
                         clear() { this.ctx().clearRect(0, 0, this.$refs.pad.width, this.$refs.pad.height); this.drawn = false; this.$refs.signature.value = '' }
                     }">
                <h2 dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Sign on screen') }}</h2>

                <form method="POST" action="{{ route('portal.agreement.sign') }}" class="space-y-4">
                    @csrf
                    <div>
                        <label dir="auto" for="agreement-name" class="mb-1 block text-sm text-ink-muted">{{ __('messages.Your full name') }}</label>
                        <input id="agreement-name" name="name" type="text" required value="{{ old('name', $booking->customer->name) }}"
                               class="w-full rounded-xl border border-gray-500 px-3 py-2" dir="auto">
                    </div>

                    <div>
                        <p dir="auto" class="mb-1 text-sm text-ink-muted">{{ __('messages.Draw your signature in the box') }}</p>
                        <canvas x-ref="pad" width="600" height="180"
                                class="w-full touch-none rounded-xl border border-gray-500 bg-white"
                                @mousedown="start($event)" @mousemove="move($event)" @mouseup="stop()" @mouseleave="stop()"
                                @touchstart.prevent="start($event)" @touchmove.prevent="move($event)" @touchend="stop()"></canvas>
                        <input type="hidden" name="signature" x-ref="signature">
                        <button type="button" class="mt-1 text-sm text-ink-muted underline" @click="clear()" dir="auto">{{ __('messages.Clear') }}</button>
                        <x-input-error :messages="$errors->get('signature')" class="mt-1" />
                    </div>

                    <label dir="auto" class="flex items-start gap-2 text-ink">
                        <input type="checkbox" name="agree" value="1" required class="mt-1">
                        <span>{{ __('messages.I have read this agreement and I agree to it.') }}</span>
                    </label>

                    <button type="submit" class="btn-primary w-full" dir="auto">{{ __('messages.Sign the agreement') }}</button>
                </form>
            </section>

            <section class="card">
                <h2 dir="auto" class="mb-2 text-lg font-bold text-ink">{{ __('messages.Or send a signed paper copy') }}</h2>
                <form method="POST" action="{{ route('portal.agreement.paper') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <input name="file" type="file" required class="w-full" accept="image/*,application/pdf">
                    <x-input-error :messages="$errors->get('file')" class="mt-1" />
                    <button type="submit" class="btn-primary w-full" dir="auto">{{ __('messages.Send it') }}</button>
                </form>
            </section>
        @endunless
    </div>
@endsection
