@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-4xl font-bold text-center mb-12 text-gray-800">{{ __('Trips') }}</h1>
    
    {{--
        Three buttons that swap three panels. They looked like tabs and behaved
        like tabs, but carried none of the wiring that tells assistive tech so:
        no roles, no selected state, nothing linking a button to the panel it
        controls, and no keyboard handling beyond Tab. A screen-reader user
        heard three unrelated buttons and could not tell which was active.
    --}}
    <div class="flex justify-center mb-8">
        <div class="bg-white rounded-2xl p-1 shadow-soft" role="tablist" aria-label="{{ __('Trips') }}">
            @foreach ([['current', __('Current')], ['upcoming', __('Upcoming')], ['past', __('Past')]] as $i => [$key, $label])
                <button type="button"
                        data-click="showTab" data-args="{{ json_encode([$key]) }}"
                        id="tab-{{ $key }}"
                        role="tab"
                        aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                        aria-controls="{{ $key }}-tab"
                        {{-- Only the selected tab is in the tab order; the arrow
                             keys move between them from there, which is what the
                             tab pattern expects. --}}
                        tabindex="{{ $i === 0 ? '0' : '-1' }}"
                        class="tab-button {{ $i === 0 ? 'active' : '' }} px-6 py-3 rounded-xl font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>
    
    <!-- Current Trips Tab -->
    <div id="current-tab" class="tab-content" role="tabpanel" aria-labelledby="tab-current" tabindex="0">
        @if($currentTrips->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($currentTrips as $trip)
                    @include('trips._trip-card', ['trip' => $trip])
                @endforeach
            </div>
        @else
            <div class="text-center py-12">
                <p class="text-gray-500 text-lg">{{ __('No current trips at the moment.') }}</p>
            </div>
        @endif
    </div>
    
    <!-- Upcoming Trips Tab -->
    <div id="upcoming-tab" class="tab-content hidden" role="tabpanel" aria-labelledby="tab-upcoming" tabindex="0">
        @if($upcomingTrips->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($upcomingTrips as $trip)
                    @include('trips._trip-card', ['trip' => $trip])
                @endforeach
            </div>
            <div class="mt-8">
                {{ $upcomingTrips->links() }}
            </div>
        @else
            <div class="text-center py-12">
                <p class="text-gray-500 text-lg">{{ __('No upcoming trips planned yet.') }}</p>
            </div>
        @endif
    </div>
    
    <!-- Past Trips Tab -->
    <div id="past-tab" class="tab-content hidden" role="tabpanel" aria-labelledby="tab-past" tabindex="0">
        @if($pastTrips->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($pastTrips as $trip)
                    @include('trips._trip-card', ['trip' => $trip])
                @endforeach
            </div>
            <div class="mt-8">
                {{ $pastTrips->links() }}
            </div>
        @else
            <div class="text-center py-12">
                <p class="text-gray-500 text-lg">{{ __('No past trips to display.') }}</p>
            </div>
        @endif
    </div>
</div>

<style>
.tab-button.active {
    background-color: {{ \App\Support\Brand::WINE }};
    color: white;
}

.tab-button:not(.active) {
    color: {{ \App\Support\Brand::INK_MUTED }};
}

.tab-button:not(.active):hover {
    color: {{ \App\Support\Brand::WINE }};
}
</style>

<script nonce="@cspNonce">
    const TRIP_TABS = ['current', 'upcoming', 'past'];

    function showTab(tabName, moveFocus = false) {
        TRIP_TABS.forEach(name => {
            const button = document.getElementById('tab-' + name);
            const panel = document.getElementById(name + '-tab');
            const selected = name === tabName;

            panel.classList.toggle('hidden', !selected);
            button.classList.toggle('active', selected);

            // The visual state and the announced state come from the same
            // flag, so they cannot drift apart.
            button.setAttribute('aria-selected', selected ? 'true' : 'false');
            button.setAttribute('tabindex', selected ? '0' : '-1');
        });

        if (moveFocus) {
            document.getElementById('tab-' + tabName).focus();
        }
    }

    // Arrow keys move between tabs, Home and End jump to the ends. Without
    // this the roles above would promise a keyboard interface the page did
    // not actually implement, which is worse than no roles at all.
    document.querySelectorAll('[role="tab"]').forEach(button => {
        button.addEventListener('keydown', event => {
            const current = TRIP_TABS.indexOf(button.id.replace('tab-', ''));
            let next = null;

            if (event.key === 'ArrowRight') next = (current + 1) % TRIP_TABS.length;
            if (event.key === 'ArrowLeft') next = (current - 1 + TRIP_TABS.length) % TRIP_TABS.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = TRIP_TABS.length - 1;

            if (next !== null) {
                event.preventDefault();
                showTab(TRIP_TABS[next], true);
            }
        });
    });
</script>
@endsection
