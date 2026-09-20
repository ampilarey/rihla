<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Services\Payments\SlipVault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to a transfer slip.
 *
 * The same three gates as a document download, for the same reason: the
 * signature on the URL is checked by middleware, the policy is asked, and
 * the download is written to the audit trail before a byte is sent. A slip
 * carries an account number, a name and often a balance.
 *
 * **Every download is audited, not just every change.** "Who has had a copy
 * of this?" is the question asked after something goes wrong, and a trail
 * that records only uploads cannot answer it.
 */
class PaymentSlipController extends Controller
{
    public function __construct(private readonly SlipVault $vault) {}

    public function show(Payment $payment, Request $request): StreamedResponse
    {
        $this->authorize('download', $payment);

        abort_unless($this->vault->exists($payment), 404);

        $this->recordDownload($payment, $request);

        return Storage::disk((string) $payment->slip_disk)->download(
            (string) $payment->slip_path,
            $payment->slip_original_filename ?? 'slip',
        );
    }

    /**
     * Into `audit_logs`, not a second trail of its own.
     *
     * The filename and the checksum are recorded; the file is not, and
     * neither is anything that would let somebody reconstruct it.
     */
    private function recordDownload(Payment $payment, Request $request): void
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => AuditLog::DOWNLOADED,
            'auditable_type' => $payment->getMorphClass(),
            'auditable_id' => $payment->getKey(),
            'old_values' => null,
            'new_values' => [
                'booking_id' => $payment->booking_id,
                'reference' => $payment->reference,
                'original_filename' => $payment->slip_original_filename,
                'checksum' => $payment->slip_checksum,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'url' => $request->fullUrl(),
        ]);
    }
}
