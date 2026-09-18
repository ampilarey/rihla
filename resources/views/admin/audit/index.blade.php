@extends('layouts.app')

@section('title', __('Audit log'))

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-ink">{{ __('Audit log') }}</h1>
        <p class="text-ink-muted mt-1">
            {{ __('Every change made to site content, newest first.') }}
        </p>
    </div>

    <form method="GET" class="flex flex-wrap gap-3 mb-6">
        <select name="event" class="rounded-lg border-gray-300 text-sm">
            <option value="">{{ __('All events') }}</option>
            @foreach (['created', 'updated', 'deleted'] as $event)
                <option value="{{ $event }}" @selected(request('event') === $event)>
                    {{ __(ucfirst($event)) }}
                </option>
            @endforeach
        </select>

        <select name="subject" class="rounded-lg border-gray-300 text-sm">
            <option value="">{{ __('All records') }}</option>
            @foreach ($subjects as $subject)
                <option value="{{ $subject }}" @selected(request('subject') === $subject)>
                    {{ $subject }}
                </option>
            @endforeach
        </select>

        <button type="submit" class="btn-primary text-sm">{{ __('Filter') }}</button>

        @if (request()->hasAny(['event', 'subject', 'user']))
            <a href="{{ route('admin.audit.index') }}" class="text-sm text-wine-500 self-center">
                {{ __('Clear') }}
            </a>
        @endif
    </form>

    <div class="bg-white shadow-sm rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-cream-deep">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold text-ink">{{ __('When') }}</th>
                    <th class="px-4 py-3 text-left font-semibold text-ink">{{ __('Who') }}</th>
                    <th class="px-4 py-3 text-left font-semibold text-ink">{{ __('Event') }}</th>
                    <th class="px-4 py-3 text-left font-semibold text-ink">{{ __('Record') }}</th>
                    <th class="px-4 py-3 text-left font-semibold text-ink">{{ __('Changed') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap text-ink-muted">
                            <time datetime="{{ $log->created_at->toAtomString() }}">
                                {{ $log->created_at->format('Y-m-d H:i') }}
                            </time>
                        </td>
                        <td class="px-4 py-3">
                            {{ $log->actor }}
                            @if ($log->user_email)
                                <span class="block text-xs text-ink-muted">{{ $log->user_email }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span @class([
                                'px-2 py-1 rounded-full text-xs font-medium',
                                'bg-success/10 text-success-dark' => $log->event === 'created',
                                'bg-info/10 text-info-dark' => $log->event === 'updated',
                                'bg-error/10 text-error-dark' => $log->event === 'deleted',
                            ])>{{ __(ucfirst($log->event)) }}</span>
                        </td>
                        <td class="px-4 py-3 text-ink-muted">
                            {{ $log->subject }} #{{ $log->auditable_id }}
                        </td>
                        <td class="px-4 py-3 text-ink-muted">
                            {{-- Field names only. The values can hold a passport
                                 number or a customer's address, and this screen
                                 is a list, not a disclosure. --}}
                            {{ implode(', ', array_keys($log->new_values ?? $log->old_values ?? [])) ?: '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-ink-muted">
                            {{ __('Nothing recorded yet.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>
@endsection
