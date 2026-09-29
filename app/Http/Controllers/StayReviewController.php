<?php

namespace App\Http\Controllers;

use App\Exceptions\ReviewRefused;
use App\Models\ReviewInvitation;
use App\Models\Stay;
use App\Services\Stays\Reviews;
use App\Services\Stays\StayGatekeeper;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A guest reviews their stay — §16.11.
 *
 * Two doors to one form: the guest's own stay page (`/my-stay/review`,
 * behind the same session gate as the rest of it) and an invitation link
 * Rihla sends (`/stays/review/{token}`), which works once. Both go through
 * {@see Reviews::submit()}, which is where every rule is.
 */
class StayReviewController extends Controller
{
    public function __construct(private readonly Reviews $reviews) {}

    public function storeFromStay(Request $request, StayGatekeeper $gate): RedirectResponse
    {
        $stay = $gate->stay();
        abort_if($stay === null, 404);

        return $this->take($request, $stay, null, route('my-stay.home'));
    }

    public function show(string $token): Response
    {
        $invitation = $this->reviews->invitation($token);
        abort_if($invitation === null, 404);

        return response()
            ->view('stays.review', ['stay' => $invitation->stay->load(['property', 'review']), 'token' => $token])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->reviews->invitation($token);
        abort_if($invitation === null, 404);

        return $this->take($request, $invitation->stay, $invitation, route('stays.review', ['token' => $token]));
    }

    private function take(Request $request, Stay $stay, ?ReviewInvitation $invitation, string $back): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'cleanliness' => ['nullable', 'integer', 'between:1,5'],
            'accuracy' => ['nullable', 'integer', 'between:1,5'],
            'communication' => ['nullable', 'integer', 'between:1,5'],
            'value' => ['nullable', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->reviews->submit($stay, $validated + ['locale' => app()->getLocale()], $invitation);
        } catch (ReviewRefused $refusal) {
            return redirect()->to($back)->withErrors(['rating' => $refusal->getMessage()])->withInput();
        }

        return redirect()->to($back)->with('status', __('messages.Thank you for your review.'));
    }
}
