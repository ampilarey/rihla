@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="text-center">
        <h1 class="text-2xl font-bold text-gray-900 mb-4">Why Section Management</h1>
        <p class="text-gray-600 mb-6">Redirecting to edit page...</p>
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
    </div>
</div>

<script>
    // Redirect to edit page
    window.location.href = '{{ route("admin.why-sections.edit", 1) }}';
</script>
@endsection
