@extends('layouts.app')

@section('title', __('Umrah Guide'))

@push('schema')
@if ($guideSchema = \App\Support\Seo::guide($guideSteps, url()->current()))
<script type="application/ld+json">{!! \App\Support\Seo::json($guideSchema) !!}</script>
@endif
<script type="application/ld+json">{!! \App\Support\Seo::json(\App\Support\Seo::breadcrumbs([
    ['name' => config('app.name'), 'url' => route('home')],
    ['name' => __('guide.How to Perform Umrah'), 'url' => null],
])) !!}</script>
@endpush

@push('styles')
<style>
    @media print {
        /* Hide non-essential elements */
        .mobile-progress,
        .mobile-actions,
        .sticky,
        .whatsapp-fab,
        .action-buttons,
        .table-of-contents {
            display: none !important;
        }
        
        /* Reset layout for print */
        .grid {
            display: block !important;
        }
        
        .lg\\:col-span-3 {
            width: 100% !important;
        }
        
        /* Ensure proper page breaks */
        .step-card {
            page-break-inside: avoid;
            margin-bottom: 20px;
        }
        
        /* Make text readable */
        body {
            font-size: 12pt !important;
            line-height: 1.4 !important;
            color: #000 !important;
        }
        
        /* Ensure proper spacing */
        .py-6, .py-8 {
            padding: 0 !important;
        }
        
        .px-4, .px-6, .px-8 {
            padding: 0 !important;
        }
        
        /* Show all expanded content */
        [x-show="false"] {
            display: block !important;
        }
        
        /* Ensure proper colors */
        .text-gray-900 {
            color: #000 !important;
        }
        
        .bg-white {
            background: transparent !important;
        }
        
        .shadow-sm, .shadow-lg {
            box-shadow: none !important;
        }
        
        .border {
            border: 1px solid #ccc !important;
        }
    }
</style>
@endpush

@section('content')
<div class="min-h-screen bg-gray-50" 
     x-data="umrahGuide()" 
     x-init="init({{ $guideSteps->count() }})"
     dir="{{ app()->getLocale() === 'dv' ? 'rtl' : 'ltr' }}">


    <!-- Page Header -->
    <div class="bg-gradient-to-r from-wine-600 to-wine-500 text-white">
        <div class="max-w-screen-xl mx-auto px-4 md:px-6 lg:px-8 py-6 md:py-8 lg:py-12">
            <div class="text-center">
                <h1 class="text-2xl md:text-3xl lg:text-4xl xl:text-5xl font-bold mb-3 md:mb-4">
                    {{ __('How to Perform Umrah') }}
                </h1>
                <p class="text-base md:text-lg lg:text-xl text-white/90 max-w-3xl mx-auto px-2">
                    {{ __('Complete step-by-step guide for performing Umrah with proper etiquette and supplications') }}
                </p>
                
                <!-- Progress Bar -->
                <div class="mt-4 md:mt-6 max-w-2xl mx-auto px-4 mobile-progress">
                    <div class="flex items-center justify-between text-xs md:text-sm mb-2">
                        <span x-text="`${__('Step')} ${currentStep} ${__('guide.of')} {{ $guideSteps->count() }}`"></span>
                        <span x-text="`${Math.round((currentStep / {{ $guideSteps->count() }}) * 100)}%`"></span>
                    </div>
                    <div class="w-full bg-white/20 rounded-full h-2">
                        <div class="bg-white h-2 rounded-full transition-all duration-500 ease-out" 
                             :style="`width: ${(currentStep / {{ $guideSteps->count() }}) * 100}%`"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons - Mobile Optimized -->
    <div class="bg-white border-b border-gray-200 sticky top-0 z-30 action-buttons">
        <div class="max-w-screen-xl mx-auto px-4 md:px-6 lg:px-8 py-3 md:py-4">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 md:gap-4">
                <div class="flex items-center gap-2 text-xs md:text-sm text-gray-600">
                    <svg class="w-4 h-4 md:w-5 md:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span x-text="`${__('Step')} ${currentStep} ${__('guide.of')} {{ $guideSteps->count() }}`"></span>
                </div>
                
                <div class="flex items-center gap-2 md:gap-3 mobile-actions">
                    <button @click="printGuide" 
                            class="inline-flex items-center gap-1 md:gap-2 px-3 md:px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500 text-xs md:text-sm">
                        <svg class="w-3 h-3 md:w-4 md:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        <span class="hidden sm:inline">{{ __('Print') }}</span>
                    </button>
                    
                    <button @click="downloadPDF" 
                            class="inline-flex items-center gap-1 md:gap-2 px-3 md:px-4 py-2 bg-wine-500 hover:bg-wine-600 text-white rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500 text-xs md:text-sm">
                        <svg class="w-3 h-3 md:w-4 md:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        <span class="hidden sm:inline">{{ __('Download PDF') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-screen-xl mx-auto px-4 md:px-6 lg:px-8 py-6 md:py-8">
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 md:gap-8">
            
            <!-- Sticky Table of Contents (Desktop) -->
            <div class="hidden lg:block lg:col-span-1 table-of-contents">
                <div class="sticky top-32">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                            </svg>
                            {{ __('Table of Contents') }}
                        </h3>
                        
                        <nav class="space-y-2">
                            @foreach($guideSteps as $index => $step)
                                <button @click="goToStep({{ $index + 1 }})" 
                                        class="w-full text-left p-3 rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500"
                                        :class="{ 
                                            'bg-wine-500 text-white': currentStep === {{ $index + 1 }},
                                            'hover:bg-gray-100 text-gray-700': currentStep !== {{ $index + 1 }}
                                        }">
                                    <div class="flex items-center gap-3">
                                        <div class="flex-shrink-0 w-8 h-8 rounded-full border-2 flex items-center justify-center text-sm font-medium"
                                             :class="{ 
                                                 'border-white text-white': currentStep === {{ $index + 1 }},
                                                 'border-gray-300 text-gray-600': currentStep !== {{ $index + 1 }}
                                             }">
                                            {{ $index + 1 }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-sm font-medium truncate" x-text="`{{ $step->title }}`"></div>
                                        </div>
                                        <div x-show="currentStep === {{ $index + 1 }}" class="flex-shrink-0">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                    </div>
                                </button>
                            @endforeach
                        </nav>
                    </div>
                </div>
            </div>

            <!-- Main Content -->
            <div class="lg:col-span-3">
                <!-- Mobile TOC Dropdown - Enhanced -->
                <div class="lg:hidden mb-6 table-of-contents">
                    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                        <button @click="mobileTocOpen = !mobileTocOpen" 
                                class="w-full p-4 text-left flex items-center justify-between hover:bg-gray-50 transition-colors duration-200 mobile-toc-button"
                                :class="{ 'bg-gray-50': mobileTocOpen }"
                                onclick="toggleMobileToc(this)">
                            <div class="flex items-center gap-3">
                                <svg class="w-5 h-5 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                </svg>
                                <span class="font-medium text-gray-900">{{ __('Table of Contents') }}</span>
                                <span class="text-sm text-gray-500">({{ $guideSteps->count() }} {{ __('guide.steps') }})</span>
                            </div>
                            <svg class="w-5 h-5 text-gray-500 transition-transform duration-200" 
                                 :class="{ 'rotate-180': mobileTocOpen }"
                                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </button>
                        
                        <div x-show="mobileTocOpen" 
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0 transform -translate-y-2"
                             x-transition:enter-end="opacity-100 transform translate-y-0"
                             x-transition:leave="transition ease-in duration-150"
                             x-transition:leave-start="opacity-100 transform translate-y-0"
                             x-transition:leave-end="opacity-0 transform -translate-y-2"
                             class="border-t border-gray-200 mobile-toc-content"
                             style="display: none;">
                            <div class="p-4 space-y-2 max-h-96 overflow-y-auto">
                                @foreach($guideSteps as $index => $step)
                                    <button @click="goToStep({{ $index + 1 }}); mobileTocOpen = false" 
                                            class="w-full text-left p-3 rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500 hover:bg-gray-50"
                                            :class="{ 
                                                'bg-wine-500 text-white': currentStep === {{ $index + 1 }},
                                                'text-gray-700': currentStep !== {{ $index + 1 }}
                                            }"
                                            onclick="goToStepFallback({{ $index + 1 }})">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-shrink-0 w-8 h-8 rounded-full border-2 flex items-center justify-center text-sm font-medium"
                                                 :class="{ 
                                                     'border-white text-white': currentStep === {{ $index + 1 }},
                                                     'border-gray-300 text-gray-600': currentStep !== {{ $index + 1 }}
                                                 }">
                                                {{ $index + 1 }}
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="text-sm font-medium" x-text="`{{ $step->title }}`">{{ $step->title }}</div>
                                            </div>
                                            <div x-show="currentStep === {{ $index + 1 }}" class="flex-shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            </div>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- NEW: Card Grid Layout -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8 step-content">
                    @foreach($guideSteps as $index => $step)
                        <div id="step-{{ $index + 1 }}" 
                             class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden step-card hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1"
                             x-intersect:enter="currentStep = {{ $index + 1 }}"
                             x-intersect:leave="currentStep = {{ $index + 1 }}">
                            
                            <!-- Step Header -->
                            <div class="bg-gradient-to-br from-wine-600 to-wine-500 text-white p-6 step-header">
                                <div class="text-center">
                                    <div class="w-16 h-16 bg-white/20 rounded-full flex items-center justify-center text-2xl font-bold mx-auto mb-4">
                                        {{ $index + 1 }}
                                    </div>
                                    <h2 class="text-xl font-bold mb-3 leading-tight">
                                        {{ $step->title }}
                                    </h2>
                                    @if($step->summary)
                                        <p class="text-white/90 text-sm leading-relaxed">
                                            {{ $step->summary }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <!-- Step Content -->
                            <div class="p-6 step-content">
                                <!-- Image Section -->
                                @if($step->image_url)
                                    <div class="aspect-video rounded-lg overflow-hidden bg-gray-100 mb-4">
                                        <img src="{{ $step->image_url }}" 
                                             alt="{{ $step->title }}"
                                             class="w-full h-full object-cover">
                                    </div>
                                @endif
                                
                                <!-- Video Button -->
                                @if($step->video_url)
                                    <div class="mb-4">
                                        <a href="{{ $step->video_url }}" 
                                           target="_blank"
                                           class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 bg-error hover:bg-error-dark text-white rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-error focus:ring-offset-2">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z"/>
                                            </svg>
                                            {{ __('Watch Video') }}
                                        </a>
                                    </div>
                                @endif

                                <!-- Expandable Details -->
                                @if($step->details)
                                    <div class="mb-4">
                                        <button @click="toggleDetails({{ $index }})" 
                                                class="w-full text-left p-3 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2">
                                            <div class="flex items-center justify-between">
                                                <span class="font-medium text-gray-900">{{ __('Show Details') }}</span>
                                                <svg class="w-4 h-4 text-gray-500 transition-transform duration-200" 
                                                     :class="{ 'rotate-180': expandedDetails.includes({{ $index }}) }"
                                                     fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                            </div>
                                        </button>
                                        <div x-show="expandedDetails.includes({{ $index }})" 
                                             x-transition:enter="transition ease-out duration-200"
                                             x-transition:enter-start="opacity-0 transform -translate-y-2"
                                             x-transition:enter-end="opacity-100 transform translate-y-0"
                                             x-transition:leave="transition ease-in duration-150"
                                             x-transition:leave-start="opacity-100 transform translate-y-0"
                                             x-transition:leave-end="opacity-0 transform -translate-y-2"
                                             class="mt-3 p-4 bg-gray-50 rounded-lg">
                                            <p class="text-gray-700 text-sm leading-relaxed">
                                                {{ $step->details }}
                                            </p>
                                        </div>
                                    </div>
                                @endif

                                <!-- Checklist -->
                                @if($step->hasChecklist())
                                    <div class="mb-4">
                                        <h4 class="text-base font-semibold text-gray-900 flex items-center gap-2 mb-3">
                                            <svg class="w-4 h-4 text-wine-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 00-2 2v2h2V5z"></path>
                                            </svg>
                                            {{ __('Checklist') }}
                                        </h4>
                                        <div class="space-y-2">
                                            @foreach($step->checklist as $item)
                                                <div class="flex items-center gap-2 text-sm text-gray-700">
                                                    <div class="w-4 h-4 border-2 border-wine-500 rounded flex items-center justify-center">
                                                        <svg class="w-3 h-3 text-wine-500 hidden" fill="currentColor" viewBox="0 0 24 24">
                                                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                                        </svg>
                                                    </div>
                                                    {{ $item }}
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <!-- Dua Section -->
                                @if($step->hasDua())
                                    <div class="mb-4">
                                        <h4 class="text-base font-semibold text-gray-900 flex items-center gap-2 mb-3">
                                            <svg class="w-4 h-4 text-gold-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                            </svg>
                                            {{ __('Du\'a') }}
                                        </h4>
                                        <div class="p-3 bg-gold-500/10 border-l-4 border-gold-500 rounded-r">
                                            {{-- Arabic: neither Inter nor the Thaana face covers it,
                                                 so without font-arabic a supplication renders in
                                                 whatever the device happens to have. --}}
                                            <p class="font-arabic text-gray-700 text-base" dir="rtl" lang="ar">
                                                {{ $step->dua_text }}
                                            </p>
                                        </div>
                                    </div>
                                @endif

                                <!-- Fiqh Notes -->
                                @if($step->hasFiqhNotes())
                                    <div class="mb-4">
                                        <h4 class="text-base font-semibold text-gray-900 flex items-center gap-2 mb-3">
                                            <svg class="w-4 h-4 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                                            </svg>
                                            {{ __('Fiqh Notes') }}
                                        </h4>
                                        <div class="p-3 bg-warning/10 border-l-4 border-warning rounded-r">
                                            {{-- fiqh_notes is a JSON array: one note per school of thought. --}}
                                            <ul class="text-gray-700 text-sm leading-relaxed list-disc list-inside space-y-1">
                                                @foreach ((array) $step->fiqh_notes as $fiqhNote)
                                                    <li>{{ $fiqhNote }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                @endif

                                <!-- Reference -->
                                @if($step->reference_text)
                                    {{-- Seeded for every step and stored all along, but no view
                                         had ever rendered it. For a licensed Umrah operator the
                                         source of each ritual instruction is the credibility. --}}
                                    <div class="mb-4">
                                        <h4 class="text-base font-semibold text-gray-900 flex items-center gap-2 mb-3">
                                            <svg class="w-4 h-4 text-gold-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                            </svg>
                                            {{ __('guide.Reference') }}
                                        </h4>
                                        <p class="text-gray-600 text-sm leading-relaxed italic">
                                            {{ $step->reference_text }}
                                        </p>
                                    </div>
                                @endif

                                <!-- View Full Step Button -->
                                <div class="mt-6">
                                    <button @click="goToStep({{ $index + 1 }})" 
                                            class="w-full px-4 py-3 bg-wine-500 hover:bg-wine-600 text-white font-medium rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500 focus:ring-offset-2">
                                        {{ __('View Full Step') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <!-- Navigation - Mobile Optimized -->
                @if($guideSteps->count() > 1)
                    <div class="mt-8 md:mt-12 flex flex-col sm:flex-row items-center justify-between gap-4 mobile-nav-buttons">
                        <button @click="previousStep" 
                                x-show="currentStep > 1"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 md:px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm md:text-base">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                            {{ __('Previous Step') }}
                        </button>
                        
                        <button @click="nextStep" 
                                x-show="currentStep < {{ $guideSteps->count() }}"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-4 md:px-6 py-3 bg-wine-500 hover:bg-wine-600 text-white rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-wine-500 text-sm md:text-base">
                            {{ __('Next Step') }}
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- WhatsApp FAB - Mobile Optimized -->
    <div class="fixed bottom-4 md:bottom-6 right-4 md:right-6 z-50 whatsapp-fab">
        <a href="{{ \App\Support\Contact::whatsappUrl(__('I need help with the Umrah guide')) }}" 
           target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center justify-center w-12 h-12 md:w-14 md:h-14 bg-success hover:bg-success-dark text-white rounded-full shadow-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-success focus:ring-offset-2">
            <svg class="w-6 h-6 md:w-7 md:h-7" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.87 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0020.885 3.488"/>
            </svg>
        </a>
    </div>
</div>

<script>
function umrahGuide() {
    return {
        currentStep: 1,
        totalSteps: {{ $guideSteps->count() }},
        mobileTocOpen: false,
        expandedDetails: [],
        
        init(totalSteps) {
            this.totalSteps = totalSteps;
            
            // Debug logging
            console.log('UmrahGuide initialized with', totalSteps, 'steps');
            console.log('Alpine.js available:', typeof Alpine !== 'undefined');
            
            // Scroll to top step on page load
            this.$nextTick(() => {
                if (window.location.hash) {
                    const stepMatch = window.location.hash.match(/step-(\d+)/);
                    if (stepMatch) {
                        this.currentStep = parseInt(stepMatch[1]);
                        console.log('Navigating to step from hash:', this.currentStep);
                    }
                }
            });
        },
        
        goToStep(stepNumber) {
            console.log('Going to step:', stepNumber);
            this.currentStep = stepNumber;
            const element = document.getElementById(`step-${stepNumber}`);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
                window.location.hash = `step-${stepNumber}`;
            } else {
                console.error('Step element not found:', `step-${stepNumber}`);
            }
        },
        
        nextStep() {
            if (this.currentStep < this.totalSteps) {
                this.goToStep(this.currentStep + 1);
            }
        },
        
        previousStep() {
            if (this.currentStep > 1) {
                this.goToStep(this.currentStep - 1);
            }
        },
        
        toggleDetails(stepIndex) {
            const index = this.expandedDetails.indexOf(stepIndex);
            if (index > -1) {
                this.expandedDetails.splice(index, 1);
            } else {
                this.expandedDetails.push(stepIndex);
            }
        },
        
        printGuide() {
            console.log('Printing guide...');
            window.print();
        },
        
        downloadPDF() {
            console.log('Downloading PDF...');
            window.location.href = '{{ route("guide.pdf") }}';
        }
    }
}

// Fallback for when Alpine.js is not loaded
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Alpine === 'undefined') {
        console.error('Alpine.js not loaded! Adding fallback functionality...');
        
        // Add basic fallback functionality
        const mobileTocButton = document.querySelector('.mobile-toc-button');
        if (mobileTocButton) {
            mobileTocButton.addEventListener('click', function() {
                const tocContent = this.nextElementSibling;
                if (tocContent) {
                    tocContent.style.display = tocContent.style.display === 'none' ? 'block' : 'none';
                }
            });
        }
    }
});

// Fallback functions for mobile TOC
function toggleMobileToc(button) {
    const tocContent = button.nextElementSibling;
    if (tocContent) {
        const isVisible = tocContent.style.display !== 'none';
        tocContent.style.display = isVisible ? 'none' : 'block';
        
        // Update button state
        const icon = button.querySelector('svg:last-child');
        if (icon) {
            icon.style.transform = isVisible ? 'rotate(0deg)' : 'rotate(180deg)';
        }
        
        // Update button background
        if (isVisible) {
            button.classList.remove('bg-gray-50');
        } else {
            button.classList.add('bg-gray-50');
        }
    }
}

function goToStepFallback(stepNumber) {
    console.log('Fallback: Going to step:', stepNumber);
    const element = document.getElementById(`step-${stepNumber}`);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        window.location.hash = `step-${stepNumber}`;
        
        // Close mobile TOC
        const tocContent = document.querySelector('.mobile-toc-content');
        if (tocContent) {
            tocContent.style.display = 'none';
        }
        
        // Update current step display
        const stepDisplay = document.querySelector('[x-text*="Step"]');
        if (stepDisplay) {
            stepDisplay.textContent = `Step ${stepNumber} of {{ $guideSteps->count() }}`;
        }
    } else {
        console.error('Step element not found:', `step-${stepNumber}`);
    }
}
</script>

<!-- Print Styles -->
<style>
@media print {
    .sticky,
    .fixed,
    button,
    .lg\\:hidden {
        display: none !important;
    }
    
    body {
        background: white !important;
        color: black !important;
    }
    
    .bg-gradient-to-r {
        background: #f3f4f6 !important;
        color: black !important;
    }
    
    .shadow-sm,
    .shadow-lg {
        box-shadow: none !important;
    }
    
    .border {
        border: 1px solid #d1d5db !important;
    }
    
    .max-w-screen-xl {
        max-width: none !important;
    }
    
    .px-4,
    .px-6,
    .px-8 {
        padding-left: 0 !important;
        padding-right: 0 !important;
    }
    
    .py-8 {
        padding-top: 0 !important;
        padding-bottom: 0 !important;
    }
    
    .space-y-8 > * + * {
        margin-top: 2rem !important;
    }
    
    .space-y-6 > * + * {
        margin-top: 1.5rem !important;
    }
}

/* Mobile-specific improvements */
@media (max-width: 768px) {
    .mobile-toc-button {
        touch-action: manipulation;
    }
    
    .step-content {
        font-size: 16px; /* Prevents zoom on iOS */
    }
    
    /* Ensure mobile TOC is properly visible */
    .lg\\:hidden .border-t {
        border-top-width: 1px !important;
    }
    
    /* Mobile-friendly button sizes */
    button {
        min-height: 44px !important;
    }
    
    /* Mobile TOC improvements */
    .mobile-toc-content {
        max-height: 60vh !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    
    /* Mobile step improvements */
    .step-header {
        padding: 1rem !important;
    }
    
    .step-content {
        padding: 1rem !important;
    }
    
    /* Mobile navigation improvements */
    .mobile-nav-buttons {
        flex-direction: column !important;
        gap: 0.75rem !important;
    }
    
    .mobile-nav-buttons button {
        width: 100% !important;
        justify-content: center !important;
    }
    
    /* Mobile progress improvements */
    .mobile-progress {
        padding: 0 1rem !important;
    }
    
    /* Mobile action buttons */
    .mobile-actions {
        flex-direction: column !important;
        gap: 0.5rem !important;
    }
    
    .mobile-actions button {
        width: 100% !important;
        justify-content: center !important;
    }
}
</style>
@endsection
