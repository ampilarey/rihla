<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Finance\Books;
use App\Support\AuditContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The books as CSV — batch C from Hijaz. Authenticated and authorised by
 * `books.view`; every export is written to the audit trail, because a
 * spreadsheet of who owes what leaves the building.
 */
class BooksController extends Controller
{
    public function csv(Request $request, string $report): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('books.view'), 403);
        abort_unless(in_array($report, Books::REPORTS, true), 404);

        $list = app(Books::class)->document($report, $request->query());

        AuditLog::create([
            'user_id' => $user->getKey(),
            'user_name' => $user->name,
            'user_email' => $user->email,
            'event' => AuditLog::DOWNLOADED,
            'channel' => AuditContext::channel(),
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->getKey(),
            'old_values' => null,
            'new_values' => ['report' => $report, 'rows' => count($list->rows), 'options' => $request->query()],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'url' => $request->fullUrl(),
        ]);

        return response()->streamDownload(
            function () use ($list): void {
                echo $list->csv();
            },
            $list->filename.'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
