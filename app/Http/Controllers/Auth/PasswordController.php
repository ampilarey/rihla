<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SignedInDevices;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * Changing a password signs out every other session — §10.4. Without
     * that, somebody who changes their password *because* they think
     * their account has been reached leaves whoever reached it exactly
     * where they were: a session already open does not re-check the
     * password. This session survives, because the person holding it has
     * just proved the old password in it.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $user->update(['password' => Hash::make($validated['password'])]);

        SignedInDevices::signOutOthers($user, $request->session()->getId());

        return back()->with('status', 'password-updated');
    }
}
