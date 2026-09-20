<?php

namespace App\Http\Controllers;

use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\Package;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The enquiry form on the contact page — §8.1.
 *
 * A message sent here becomes a tracked lead with somewhere to put an owner
 * and a next action, which is the whole point: "that alone beats a shared
 * inbox".
 *
 * WhatsApp stays the prominent option, because it is how this operator's
 * customers actually get in touch and a form that pretends otherwise is a
 * form nobody fills in. This is for the person who is browsing at midnight
 * and does not want to start a chat.
 */
class EnquiryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // One of the two, not both: somebody who leaves a phone number
            // has given us enough, and demanding an email as well loses the
            // enquiries of people who do not use one.
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'message' => ['nullable', 'string', 'max:2000'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:60'],
        ], [
            'phone.required_without' => __('messages.Leave us a phone number or an email so we can reply.'),
            'email.required_without' => __('messages.Leave us a phone number or an email so we can reply.'),
        ]);

        // A field no human sees. Read from the request rather than
        // validated: a validation error tells whoever filled it in that
        // they were caught, and a rejection is not a thank-you. The first
        // version used a `size:0` rule and refused the request outright,
        // which is both of those mistakes at once.
        if (filled($request->input('website'))) {
            return $this->thanks();
        }

        $existing = $this->recentDuplicate($validated);

        if ($existing !== null) {
            // Pressing send twice, or coming back an hour later having
            // forgotten. Recorded on the enquiry that already exists rather
            // than creating a second one for somebody to work in parallel.
            $existing->record(
                'They sent the form again: '.($validated['message'] ?? '' ?: '(no message)'),
                EnquiryNote::NOTE,
            );

            return $this->thanks();
        }

        Enquiry::create([
            'source' => Enquiry::WEB,
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'message' => $validated['message'] ?? null,
            'package_id' => $validated['package_id'] ?? null,
            'party_size' => $validated['party_size'] ?? null,
        ]);

        return $this->thanks();
    }

    /**
     * The same person, minutes ago.
     *
     * Matched on the normalised phone number first, because that is what
     * identifies somebody here, and on the email when there is no number.
     */
    private function recentDuplicate(array $validated): ?Enquiry
    {
        $since = now()->subHours(6);
        $key = PhoneNumber::key($validated['phone'] ?? null);

        if ($key !== null) {
            $match = Enquiry::where('created_at', '>=', $since)
                ->get()
                ->first(fn (Enquiry $enquiry): bool => PhoneNumber::key($enquiry->phone) === $key);

            if ($match !== null) {
                return $match;
            }
        }

        if (filled($validated['email'] ?? null)) {
            return Enquiry::where('created_at', '>=', $since)
                ->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $validated['email'])])
                ->first();
        }

        return null;
    }

    /**
     * The same answer whatever happened.
     *
     * A form that says "you already sent this" tells somebody probing it
     * which addresses are on file.
     */
    private function thanks(): RedirectResponse
    {
        return redirect()->route('contact')
            ->with('status', __('messages.Thank you — we have your message and will come back to you.'));
    }

    /** Packages a visitor might be asking about, for the form's dropdown. */
    public static function packageOptions(): Collection
    {
        return Package::published()->orderBy('sort_order')->get(['id', 'title']);
    }
}
