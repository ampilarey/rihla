@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-4xl font-bold text-center mb-12 text-gray-800">{{ __('Trips') }}</h1>
    
    <!-- Tabs -->
    <div class="flex justify-center mb-8">
        <div class="bg-white rounded-2xl p-1 shadow-soft">
            <button onclick="showTab('current')" id="tab-current" class="tab-button active px-6 py-3 rounded-xl font-medium transition-colors">
                {{ __('Current') }}
            </button>
            <button onclick="showTab('upcoming')" id="tab-upcoming" class="tab-button px-6 py-3 rounded-xl font-medium transition-colors">
                {{ __('Upcoming') }}
            </button>
            <button onclick="showTab('past')" id="tab-past" class="tab-button px-6 py-3 rounded-xl font-medium transition-colors">
                {{ __('Past') }}
            </button>
        </div>
    </div>
    
    <!-- Current Trips Tab -->
    <div id="current-tab" class="tab-content">
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
    <div id="upcoming-tab" class="tab-content hidden">
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
    <div id="past-tab" class="tab-content hidden">
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

<script>
function showTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.add('hidden');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById(tabName + '-tab').classList.remove('hidden');
    
    // Add active class to selected button
    document.getElementById('tab-' + tabName).classList.add('active');
}
</script>
@endsection
