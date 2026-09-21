<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SignedInDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Where you are signed in, and how to stop being — §10.4.
 *
 * Plain routes beside `/two-factor` rather than a Filament page, for the
 * same reason: this belongs to the account, not to the staff panel, and
 * everybody with a login has one — including somebody whose role never
 * opens `/staff`.
 */
class DevicesController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('devices.index', [
            'available' => SignedInDevices::available(),
            'devices' => SignedInDevices::for($user, $request->session()->getId()),
        ]);
    }

    /**
     * Sign one device out.
     *
     * No password asked for. Signing a device out is not a dangerous
     * action — the worst outcome of doing it by accident is signing in
     * again — and a password prompt in front of it is how somebody leaves
     * a session they do not recognise alive because they could not
     * remember their password at that moment.
     */
    public function destroy(Request $request, string $device): RedirectResponse
    {
        $user = $this->user($request);

        if ($device === $request->session()->getId()) {
            return redirect()->route('devices.index')->withErrors([
                'device' => __('messages.That is this device. Use Sign out to leave here.'),
            ]);
        }

        $signedOut = SignedInDevices::signOut($user, $device);

        return redirect()->route('devices.index')->with(
            'status',
            $signedOut
                ? __('messages.That device has been signed out.')
                : __('messages.That device was already signed out.'),
        );
    }

    /** Sign out everywhere else, keeping this one. */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $count = SignedInDevices::signOutOthers($user, $request->session()->getId());

        return redirect()->route('devices.index')->with(
            'status',
            $count === 0
                ? __('messages.There was nowhere else signed in.')
                : trans_choice('messages.Signed out of one other device.|Signed out of :count other devices.', $count, ['count' => $count]),
        );
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
