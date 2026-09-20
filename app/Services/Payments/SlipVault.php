<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The transfer slip attached to a payment.
 *
 * Same discipline as the document wallet, for the same reason: a slip
 * carries an account number, a name and a balance. The disk is private and
 * `serve => false`, the filename on disk is random, and the only door is a
 * short-lived signed URL that goes through a policy and the audit trail.
 *
 * **A replaced slip is never overwritten.** The old file stays where it is
 * and a `slip_replaced` row records its path and checksum, so "what did they
 * actually send us first?" still has an answer — the same rule as [R-8] on
 * documents, without a second versioning system to maintain.
 *
 * The checksum earns its place twice here as well: it proves the file has
 * not changed under us, and it catches the customer who sends the same
 * image three times on WhatsApp.
 */
final class SlipVault
{
    public function attach(Payment $payment, UploadedFile $file): Payment
    {
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        return DB::transaction(function () use ($payment, $file, $checksum): Payment {
            // Byte-for-byte what is already on the payment. Nothing has
            // changed, so nothing is recorded: a history full of identical
            // "replacements" makes the real one unreadable.
            if ($checksum !== null && $payment->slip_checksum === $checksum) {
                return $payment;
            }

            // Captured before the save. getOriginal() after a save returns
            // the value just written, not the one it replaced, so reading
            // it later would record the new checksum as the old one.
            $replaced = $payment->slip_path;
            $replacedChecksum = $payment->slip_checksum;

            $path = $file->storeAs(
                'payment-slips/'.$payment->getKey(),
                Str::random(40).'.'.($file->getClientOriginalExtension() ?: 'bin'),
                ['disk' => $this->disk()],
            );

            $payment->forceFill([
                'slip_disk' => $this->disk(),
                'slip_path' => $path,
                'slip_original_filename' => $file->getClientOriginalName(),
                'slip_mime_type' => $file->getClientMimeType(),
                'slip_size_bytes' => $file->getSize() ?: 0,
                'slip_checksum' => $checksum,
            ])->save();

            $payment->transactions()->create([
                'type' => $replaced === null
                    ? PaymentTransaction::SLIP_UPLOADED
                    : PaymentTransaction::SLIP_REPLACED,
                'user_id' => Auth::id(),
                // The superseded file's path and checksum, so it can still
                // be found. The file itself is not deleted.
                'payload' => $replaced === null ? null : [
                    'replaced_path' => $replaced,
                    'replaced_checksum' => $replacedChecksum,
                ],
                'created_at' => now(),
            ]);

            // A slip arriving is the thing that puts a payment in front of
            // somebody. Without this it would sit at `pending` and nobody
            // would know there was anything to look at.
            if ($payment->status === Payment::PENDING) {
                $payment->transitionTo(Payment::AWAITING_REVIEW, 'Slip received.');
            }

            return $payment->refresh();
        });
    }

    /**
     * A short-lived signed URL, which is the only route to the file.
     *
     * Short for the reason the document wallet's is short: the link is the
     * only thing between a bank slip and whoever ends up holding the URL.
     */
    public function downloadUrl(Payment $payment): string
    {
        return URL::temporarySignedRoute(
            'payments.slip',
            now()->addMinutes((int) config('payments.slips.link_minutes', 5)),
            ['payment' => $payment->getKey()],
        );
    }

    public function exists(Payment $payment): bool
    {
        return $payment->slip_path !== null
            && Storage::disk((string) $payment->slip_disk)->exists($payment->slip_path);
    }

    private function disk(): string
    {
        return (string) config('payments.slips.disk', 'documents');
    }
}
