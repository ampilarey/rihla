<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->validate([
            'event' => 'nullable|in:created,updated,deleted',
            'subject' => 'nullable|string|max:100',
            'user' => 'nullable|integer',
        ]);

        $logs = AuditLog::query()
            ->when($filters['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when(
                $filters['subject'] ?? null,
                // Matched on the class basename so the filter reads "Trip"
                // rather than requiring the fully-qualified class name.
                fn ($query, $subject) => $query->where('auditable_type', 'App\\Models\\'.$subject),
            )
            ->when($filters['user'] ?? null, fn ($query, $user) => $query->where('user_id', $user))
            ->latestFirst()
            ->paginate(50)
            ->withQueryString();

        $subjects = AuditLog::query()
            ->distinct()
            ->pluck('auditable_type')
            ->map(fn ($type) => class_basename($type))
            ->sort()
            ->values();

        return view('admin.audit.index', compact('logs', 'subjects'));
    }
}
