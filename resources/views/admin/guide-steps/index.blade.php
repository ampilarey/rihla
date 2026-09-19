@extends('layouts.app')

@section('title', 'Manage Guide Steps')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Manage Guide Steps</h1>
            <p class="mt-2 text-gray-600">Create and manage step-by-step Umrah guide content</p>
        </div>
        <a href="{{ route('admin.guide-steps.create') }}" 
           class="mt-4 md:mt-0 inline-flex items-center px-4 py-2 bg-wine-500 hover:bg-wine-600 text-white font-medium rounded-lg transition-colors duration-200">
            <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Add New Step
        </a>
    </div>

    @if(session('success'))
        <div class="mb-6 p-4 bg-success/10 border border-success/40 text-success-dark rounded-lg">
            {{ session('success') }}
        </div>
    @endif

    <!-- Bulk Actions -->
    <div class="mb-6 p-4 bg-gray-50 rounded-lg">
        <form action="{{ route('admin.guide-steps.bulk-update-status') }}" method="POST" class="flex flex-col sm:flex-row gap-4 items-center">
            @csrf
            <div class="flex items-center gap-4">
                <label class="text-sm font-medium text-gray-700">Bulk Actions:</label>
                <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    <option value="1">Publish</option>
                    <option value="0">Unpublish</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm rounded-lg transition-colors duration-200">
                    Apply
                </button>
            </div>
        </form>
    </div>

    {{-- One list. This was two, one per language, because a step was two rows
         joined by nothing but a shared step number — so the order and the
         publish state had to be kept in agreement by hand. --}}
    @if($guideSteps->isNotEmpty())
            <div class="mb-8">
                
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <input type="checkbox" class="bulk-select-all rounded border-gray-300 text-wine-500 focus:ring-wine-500">
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Step</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dhivehi</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200 sortable-table">
                                @foreach($guideSteps as $step)
                                    <tr data-id="{{ $step->id }}" class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="checkbox" name="ids[]" value="{{ $step->id }}" class="bulk-select rounded border-gray-300 text-wine-500 focus:ring-wine-500">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-medium text-gray-900">{{ $step->step_number }}</span>
                                                <div class="flex flex-col">
                                                    <button class="text-gray-400 hover:text-gray-600" data-click="moveStep" data-args="{{ json_encode([$step->id, 'up']) }}">
                                                        <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                                        </svg>
                                                    </button>
                                                    <button class="text-gray-400 hover:text-gray-600" data-click="moveStep" data-args="{{ json_encode([$step->id, 'down']) }}">
                                                        <svg aria-hidden="true" focusable="false" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="w-8 h-8 bg-wine-500 text-white rounded-full flex items-center justify-center text-sm font-bold">
                                                {{ $step->step_number }}
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">{{ $step->getTranslation('title', 'en') }}</div>
                                            @if($step->summary)
                                                <div class="text-sm text-gray-500 mt-1 line-clamp-2">{{ Str::limit($step->getTranslation('summary', 'en'), 100) }}</div>
                                            @endif
                                        </td>
                                        {{-- Whether the Dhivehi half has been written. Until it
                                             is, a Dhivehi visitor reads the English. --}}
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            @if($step->hasTranslation('title', 'dv'))
                                                <span class="text-success">Yes</span>
                                            @else
                                                <span class="text-gray-500">No</span>
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <button data-click="toggleStatus" data-args="{{ json_encode([$step->id]) }}" 
                                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium transition-colors duration-200 {{ $step->is_published ? 'bg-success/10 text-success-dark' : 'bg-gray-100 text-gray-800' }}">
                                                {{ $step->is_published ? 'Published' : 'Draft' }}
                                            </button>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex items-center gap-2">
                                                <a href="{{ route('admin.guide-steps.edit', $step) }}" 
                                                   class="text-wine-500 hover:text-wine-600 transition-colors duration-200">
                                                    Edit
                                                </a>
                                                <form action="{{ route('admin.guide-steps.destroy', $step) }}" method="POST" class="inline" data-confirm="Are you sure you want to delete this step?">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-error hover:text-error-dark transition-colors duration-200">
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

    @if($guideSteps->isEmpty())
        <div class="text-center py-12">
            <svg aria-hidden="true" focusable="false" class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No guide steps</h3>
            <p class="mt-1 text-sm text-gray-500">Get started by creating your first guide step.</p>
            <div class="mt-6">
                <a href="{{ route('admin.guide-steps.create') }}" 
                   class="inline-flex items-center px-4 py-2 bg-wine-500 hover:bg-wine-600 text-white font-medium rounded-lg transition-colors duration-200">
                    <svg aria-hidden="true" focusable="false" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                    Add Guide Step
                </a>
            </div>
        </div>
    @endif
</div>

<script nonce="@cspNonce">
// Bulk select functionality
document.addEventListener('DOMContentLoaded', function() {
    const bulkSelectAll = document.querySelector('.bulk-select-all');
    const bulkSelects = document.querySelectorAll('.bulk-select');
    
    if (bulkSelectAll) {
        bulkSelectAll.addEventListener('change', function() {
            bulkSelects.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    }
});

// Toggle step status
function toggleStatus(stepId) {
    fetch(`/admin/guide-steps/${stepId}/toggle-status`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
        },
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating step status');
    });
}

// Move step up or down
function moveStep(stepId, direction) {
    const row = document.querySelector(`tr[data-id="${stepId}"]`);
    const table = row.closest('tbody');
    const rows = Array.from(table.querySelectorAll('tr[data-id]'));
    const currentIndex = rows.findIndex(r => r.dataset.id == stepId);
    
    let newIndex;
    if (direction === 'up' && currentIndex > 0) {
        newIndex = currentIndex - 1;
    } else if (direction === 'down' && currentIndex < rows.length - 1) {
        newIndex = currentIndex + 1;
    } else {
        return;
    }
    
    // Swap rows
    const currentRow = rows[currentIndex];
    const targetRow = rows[newIndex];
    
    if (currentRow.nextSibling === targetRow) {
        table.insertBefore(targetRow, currentRow);
    } else {
        table.insertBefore(currentRow, targetRow);
        table.insertBefore(targetRow, currentRow);
    }
    
    // Update step numbers
    updateStepNumbers(table);
    
    // Save new order
    saveNewOrder(table);
}

// Update step numbers display
function updateStepNumbers(table) {
    const rows = table.querySelectorAll('tr[data-id]');
    rows.forEach((row, index) => {
        const stepNumberCell = row.querySelector('td:nth-child(3) .w-8');
        const orderCell = row.querySelector('td:nth-child(2) span');
        if (stepNumberCell) stepNumberCell.textContent = index + 1;
        if (orderCell) orderCell.textContent = index + 1;
    });
}

// Save new order to database
function saveNewOrder(table) {
    const rows = table.querySelectorAll('tr[data-id]');
    const steps = Array.from(rows).map((row, index) => ({
        id: parseInt(row.dataset.id),
        step_number: index + 1
    }));
    
    fetch('/admin/guide-steps/update-order', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ steps: steps })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating step order');
    });
}
</script>
@endsection
