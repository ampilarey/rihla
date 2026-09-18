@if($why)
<section class="py-16 bg-gray-50" id="section-why">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12">
            @if($why->image_path)
                <div class="mb-6">
                    <img src="{{ Storage::url($why->image_path) }}" 
                         alt="{{ $why->title }}" 
                         class="mx-auto max-w-md h-auto rounded-lg shadow-lg">
                </div>
            @endif
            
            <h2 class="text-4xl font-bold mb-4" style="color: {{ $why->title_color ?? '#1f2937' }};">{{ $why->title }}</h2>
            
            @if($why->subtitle)
                <p class="text-xl max-w-3xl mx-auto" style="color: {{ $why->subtitle_color ?? '#6b7280' }};">{{ $why->subtitle }}</p>
            @endif
            
                            @if($why->primary_cta_text || $why->secondary_cta_text)
                    <div class="flex flex-col sm:flex-row gap-4 justify-center mt-8">
                        @if($why->primary_cta_text && $why->primary_cta_url)
                            <a href="{{ $why->primary_cta_url }}" 
                               class="inline-flex items-center px-6 py-3 font-semibold rounded-lg transition-colors duration-200"
                               style="background-color: {{ $why->primary_cta_bg_color ?? '#2563eb' }}; color: {{ $why->primary_cta_text_color ?? '#ffffff' }};">
                                {{ $why->primary_cta_text }}
                            </a>
                        @endif
                        
                        @if($why->secondary_cta_text && $why->secondary_cta_url)
                            <a href="{{ $why->secondary_cta_url }}" 
                               class="inline-flex items-center px-6 py-3 border-2 font-semibold rounded-lg transition-colors duration-200"
                               style="background-color: {{ $why->secondary_cta_bg_color ?? '#ffffff' }}; color: {{ $why->secondary_cta_text_color ?? '#374151' }}; border-color: {{ $why->secondary_cta_bg_color ?? '#d1d5db' }};">
                                {{ $why->secondary_cta_text }}
                            </a>
                        @endif
                    </div>
                @endif
        </div>
        
        @if($why->features->count() > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
                @foreach($why->features as $feature)
                    @if($feature->link_url)
                        <a href="{{ $feature->link_url }}" 
                           class="block rounded-lg shadow-md p-6 text-center hover:shadow-lg transition-all duration-200 hover:scale-105"
                           style="background-color: {{ $feature->background_color ?? '#ffffff' }};"
                           target="_blank" rel="noopener noreferrer">
                    @else
                        <div class="rounded-lg shadow-md p-6 text-center hover:shadow-lg transition-shadow duration-200"
                             style="background-color: {{ $feature->background_color ?? '#ffffff' }};">
                    @endif
                    
                        <div class="mb-4">
                            @if($feature->image_path)
                                <img src="{{ Storage::url($feature->image_path) }}" 
                                     alt="{{ $feature->title }}" 
                                     class="w-16 h-16 mx-auto object-cover rounded-lg">
                            @elseif($feature->icon)
                                <div class="w-16 h-16 mx-auto bg-wine-50 rounded-lg flex items-center justify-center">
                                    <span class="text-2xl text-wine-500">{{ $feature->icon }}</span>
                                </div>
                            @else
                                <div class="w-16 h-16 mx-auto bg-gray-100 rounded-lg flex items-center justify-center">
                                    <span class="text-2xl text-gray-400">📋</span>
                                </div>
                            @endif
                        </div>
                        
                        <h3 class="text-xl font-semibold text-gray-900 mb-3">{{ $feature->title }}</h3>
                        
                        @if($feature->text)
                            <p class="text-gray-600 leading-relaxed">{{ $feature->text }}</p>
                        @endif
                        
                        @if($feature->link_url)
                            <div class="mt-4">
                                <span class="inline-flex items-center text-sm text-wine-500 hover:text-wine-600">
                                    {{ $feature->link_text ?: 'Learn More' }}
                                    <svg class="ml-1 w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                    </svg>
                                </span>
                            </div>
                        @endif
                    
                    @if($feature->link_url)
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</section>
@endif
