{{--
    Pulse's own dashboard, with one card removed and the order changed.

    Removed: <livewire:pulse.servers />. It is fed only by `pulse:check`,
    which is a daemon, and this host cannot keep one alive (ADR 0002). Left
    in, it would render a panel that stays empty forever — which reads as
    "the server is down" rather than "nothing is watching". The recorder is
    commented out in config/pulse.php; restore the two together.

    Every other card can be empty and still be telling the truth: no
    exceptions yet, no queued jobs yet, cache recording switched off. Those
    stay.

    Exceptions and slow requests are first because they are the two that
    answer "is something wrong right now", and this dashboard is only ever
    read by someone who came here to ask that.
--}}
<x-pulse>
    <livewire:pulse.exceptions cols="6" rows="2" />

    <livewire:pulse.slow-requests cols="6" rows="2" />

    <livewire:pulse.slow-queries cols="12" />

    <livewire:pulse.usage cols="4" rows="2" />

    <livewire:pulse.queues cols="4" />

    {{-- Empty unless PULSE_CACHE_INTERACTIONS_ENABLED=true; see config/pulse.php. --}}
    <livewire:pulse.cache cols="4" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="6" />
</x-pulse>
