<?php

namespace App\Http\Controllers;

use App\Exceptions\DeskRefusal;
use App\Models\User;
use App\Providers\Filament\HostPanelProvider;
use App\Services\Hosts\HostTeam;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Accepting an invitation to work for a host — §16.6, §16.10 *Team*.
 *
 * The link names one address. Somebody already signed in with it joins
 * with one button; somebody with an account signs in first; somebody new
 * sets a name and password for that address, and nothing else — the
 * address is the invitation's, not theirs to type.
 */
class HostJoinController extends Controller
{
    public function __construct(private readonly HostTeam $team) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->team->find($token);
        abort_if($invitation === null, 404);

        $user = $request->user();

        if ($user === null) {
            // Back here after signing in.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return response()->view('host.join', [
            'invitation' => $invitation->load('partner'),
            'token' => $token,
            'user' => $user,
            'hasAccount' => $user === null && User::query()->whereRaw('lower(email) = ?', [strtolower($invitation->email)])->exists(),
            'loginUrl' => url(HostPanelProvider::PATH.'/login'),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->team->find($token);
        abort_if($invitation === null, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $this->team->accept($invitation, $user);
        } catch (DeskRefusal $refusal) {
            return back()->withErrors(['join' => $refusal->getMessage()]);
        }

        return redirect()->to(url(HostPanelProvider::PATH.'/'.$invitation->partner->slug));
    }

    public function register(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->team->find($token);
        abort_if($invitation === null, 404);
        abort_if($request->user() !== null, 403);

        if (User::query()->whereRaw('lower(email) = ?', [strtolower($invitation->email)])->exists()) {
            return back()->withErrors(['join' => 'There is already an account for '.$invitation->email.'. Sign in with it to accept.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $invitation->email,
            'password' => Hash::make($data['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        $this->team->accept($invitation, $user);

        return redirect()->to(url(HostPanelProvider::PATH.'/'.$invitation->partner->slug));
    }
}
