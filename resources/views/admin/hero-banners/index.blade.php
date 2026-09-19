@extends('layouts.app')

@section('title', 'Manage Hero Banners')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Manage Hero Banners</h1>
        <a href="{{ route('admin.hero-banners.create') }}" 
           class="btn-primary">
            Add New Banner
        </a>
    </div>

    @if(session('success'))
        <div class="bg-success/10 border border-success/40 text-success-dark px-4 py-3 rounded mb-6">
            {{ session('success') }}
        </div>
    @endif

    <!-- English Banners -->
    @if(isset($banners['en']) && $banners['en']->count() > 0)
        <div class="mb-12">
            <h2 class="text-2xl font-semibold text-gray-700 mb-6 flex items-center">
                <span class="bg-wine-50 text-wine-600 px-3 py-1 rounded-full text-sm font-medium mr-3">EN</span>
                English Banners
            </h2>
            
            <div class="bg-white rounded-lg shadow-sm border">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Schedule</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="en-banners">
                            @foreach($banners['en'] as $banner)
                                <tr data-id="{{ $banner->id }}" class="hover:bg-gray-50 cursor-move">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($banner->image_path)
                                            <img src="{{ $banner->image_url }}" 
                                                 alt="{{ $banner->title }}"
                                                 class="w-16 h-12 object-cover rounded"
         loading="lazy"
         decoding="async">
                                        @else
                                            <div class="w-16 h-12 bg-gray-200 rounded flex items-center justify-center">
                                                <span class="text-gray-400 text-xs">No Image</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900">{{ $banner->title }}</div>
                                        @if($banner->subtitle)
                                            <div class="text-sm text-gray-500">{{ Str::limit($banner->subtitle, 60) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $banner->is_active ? 'bg-success/10 text-success-dark' : 'bg-error/10 text-error-dark' }}">
                                            {{ $banner->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $banner->sort_order }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        @if($banner->start_at || $banner->end_at)
                                            @if($banner->start_at)
                                                <div>From: {{ $banner->start_at->format('M j, Y') }}</div>
                                            @endif
                                            @if($banner->end_at)
                                                <div>To: {{ $banner->end_at->format('M j, Y') }}</div>
                                            @endif
                                        @else
                                            <span class="text-gray-400">Always visible</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="{{ route('admin.hero-banners.show', $banner) }}" 
                                               class="text-wine-500 hover:text-wine-600">View</a>
                                            <a href="{{ route('admin.hero-banners.edit', $banner) }}" 
                                               class="text-gold-700 hover:text-gold-700">Edit</a>
                                            <form method="POST" action="{{ route('admin.hero-banners.toggle-status', $banner) }}" class="inline">
                                                @csrf
                                                <button type="submit" 
                                                        class="text-gray-600 hover:text-gray-800">
                                                    {{ $banner->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                            <form data-confirm="Are you sure you want to delete this banner?" method="POST" action="{{ route('admin.hero-banners.destroy', $banner) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" 
                                                        class="text-error hover:text-error-dark">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- Dhivehi Banners -->
    @if(isset($banners['dv']) && $banners['dv']->count() > 0)
        <div class="mb-12">
            <h2 class="text-2xl font-semibold text-gray-700 mb-6 flex items-center">
                <span class="bg-success/10 text-success-dark px-3 py-1 rounded-full text-sm font-medium mr-3">ދިވެހިބަހުން</span>
                Dhivehi Banners
            </h2>
            
            <div class="bg-white rounded-lg shadow-sm border">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Content</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Schedule</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="dv-banners">
                            @foreach($banners['dv'] as $banner)
                                <tr data-id="{{ $banner->id }}" class="hover:bg-gray-50 cursor-move">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($banner->image_path)
                                            <img src="{{ $banner->image_url }}" 
                                                 alt="{{ $banner->title }}"
                                                 class="w-16 h-12 object-cover rounded"
         loading="lazy"
         decoding="async">
                                        @else
                                            <div class="w-16 h-12 bg-gray-200 rounded flex items-center justify-center">
                                                <span class="text-gray-400 text-xs">No Image</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900">{{ $banner->title }}</div>
                                        @if($banner->subtitle)
                                            <div class="text-sm text-gray-500">{{ Str::limit($banner->subtitle, 60) }}</div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $banner->is_active ? 'bg-success/10 text-success-dark' : 'bg-error/10 text-error-dark' }}">
                                            {{ $banner->is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        {{ $banner->sort_order }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        @if($banner->start_at || $banner->end_at)
                                            @if($banner->start_at)
                                                <div>From: {{ $banner->start_at->format('M j, Y') }}</div>
                                            @endif
                                            @if($banner->end_at)
                                                <div>To: {{ $banner->end_at->format('M j, Y') }}</div>
                                            @endif
                                        @else
                                            <span class="text-gray-400">Always visible</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="{{ route('admin.hero-banners.show', $banner) }}" 
                                               class="text-wine-500 hover:text-wine-600">View</a>
                                            <a href="{{ route('admin.hero-banners.edit', $banner) }}" 
                                               class="text-gold-700 hover:text-gold-700">Edit</a>
                                            <form method="POST" action="{{ route('admin.hero-banners.toggle-status', $banner) }}" class="inline">
                                                @csrf
                                                <button type="submit" 
                                                        class="text-gray-600 hover:text-gray-800">
                                                    {{ $banner->is_active ? 'Deactivate' : 'Activate' }}
                                                </button>
                                            </form>
                                            <form data-confirm="Are you sure you want to delete this banner?" method="POST" action="{{ route('admin.hero-banners.destroy', $banner) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" 
                                                        class="text-error hover:text-error-dark">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if((!isset($banners['en']) || $banners['en']->count() == 0) && (!isset($banners['dv']) || $banners['dv']->count() == 0))
        <div class="text-center py-12">
            <div class="text-gray-400 text-6xl mb-4">📢</div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">No Hero Banners</h3>
            <p class="text-gray-500 mb-6">Get started by creating your first hero banner.</p>
            <a href="{{ route('admin.hero-banners.create') }}" class="btn-primary">
                Create First Banner
            </a>
        </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize drag and drop for English banners
    const enBanners = document.getElementById('en-banners');
    if (enBanners) {
        new Sortable(enBanners, {
            animation: 150,
            onEnd: function(evt) {
                updateOrder('en', enBanners);
            }
        });
    }

    // Initialize drag and drop for Dhivehi banners
    const dvBanners = document.getElementById('dv-banners');
    if (dvBanners) {
        new Sortable(dvBanners, {
            animation: 150,
            onEnd: function(evt) {
                updateOrder('dv', dvBanners);
            }
        });
    }

    function updateOrder(locale, container) {
        const banners = Array.from(container.querySelectorAll('tr[data-id]')).map((row, index) => ({
            id: row.dataset.id,
            sort_order: index
        }));

        fetch('{{ route("admin.hero-banners.update-order") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ banners })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Update the order numbers in the table
                banners.forEach((banner, index) => {
                    const row = container.querySelector(`tr[data-id="${banner.id}"]`);
                    const orderCell = row.querySelector('td:nth-child(4)');
                    if (orderCell) {
                        orderCell.textContent = index;
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error updating order:', error);
        });
    }
});
</script>
@endsection
