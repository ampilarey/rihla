<?php

namespace App\Services\Hosts;

use App\Exceptions\DeskRefusal;
use App\Models\HostInvitation;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\User;
use App\Support\HostRole;
use App\Support\SignedInDevices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The people who work for a host — §16.6, §16.10 *Team*.
 *
 * The owner invites by e-mail with a role; the link is shown on screen as
 * well as sent, because mail may not be configured (the portal-link
 * pattern). An invitation is a credential: stored as a hash, good for a
 * week, spent by the one person whose address it names. A host always
 * keeps at least one owner.
 */
class HostTeam
{
    public const INVITATION_DAYS = 7;

    /**
     * @return string the token, shown once
     *
     * @throws DeskRefusal
     */
    public function invite(Partner $host, string $email, string $role, ?User $by = null): string
    {
        $email = Str::lower(trim($email));

        if (! in_array($role, HostRole::ALL, true)) {
            throw new DeskRefusal('Choose owner, manager or reception.');
        }

        $alreadyIn = HostMembership::query()
            ->where('partner_id', $host->getKey())
            ->whereNotNull('accepted_at')
            ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$email]))
            ->exists();

        if ($alreadyIn) {
            throw new DeskRefusal('That person is already on your team.');
        }

        $token = Str::random(48);

        DB::transaction(function () use ($host, $email, $role, $by, $token): void {
            // A new invitation replaces any older one to the same address,
            // so the link most recently sent is the only one that works.
            HostInvitation::query()
                ->where('partner_id', $host->getKey())
                ->where('email', $email)
                ->live()
                ->update(['expires_at' => now()]);

            HostInvitation::create([
                'partner_id' => $host->getKey(),
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'invited_by' => $by?->getKey(),
                'expires_at' => now()->addDays(self::INVITATION_DAYS),
            ]);
        });

        return $token;
    }

    public function find(string $token): ?HostInvitation
    {
        return HostInvitation::query()->live()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * The invited person joins — only the person whose address it names.
     *
     * @throws DeskRefusal
     */
    public function accept(HostInvitation $invitation, User $user): HostMembership
    {
        if (! $invitation->isLive()) {
            throw new DeskRefusal('This invitation has been used or has expired. Ask for a new one.');
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw new DeskRefusal('This invitation was sent to '.$invitation->email.'. Sign in with that address to accept it.');
        }

        return DB::transaction(function () use ($invitation, $user): HostMembership {
            $membership = HostMembership::query()->firstOrNew([
                'partner_id' => $invitation->partner_id,
                'user_id' => $user->getKey(),
            ]);

            $membership->forceFill([
                'role' => $invitation->role,
                'invited_by' => $invitation->invited_by,
                'accepted_at' => now(),
            ])->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            return $membership;
        });
    }

    /** @throws DeskRefusal */
    public function changeRole(HostMembership $membership, string $role): HostMembership
    {
        if (! in_array($role, HostRole::ALL, true)) {
            throw new DeskRefusal('Choose owner, manager or reception.');
        }

        if ($membership->role === HostRole::OWNER && $role !== HostRole::OWNER) {
            $this->assertNotLastOwner($membership);
        }

        $membership->forceFill(['role' => $role])->save();

        return $membership;
    }

    /**
     * Take somebody off the team. Their access ends on their next request
     * (the panel checks the membership every time); if this was the only
     * place they worked and they are not Rihla staff, their sessions end
     * now as well.
     *
     * @throws DeskRefusal
     */
    public function remove(HostMembership $membership): void
    {
        if ($membership->role === HostRole::OWNER) {
            $this->assertNotLastOwner($membership);
        }

        $user = $membership->user;
        $membership->delete();

        if ($user !== null && ! $user->hosts()->exists() && $user->getRoleNames()->isEmpty()) {
            SignedInDevices::signOutEverywhere($user);
        }
    }

    /** @throws DeskRefusal */
    private function assertNotLastOwner(HostMembership $membership): void
    {
        $owners = HostMembership::query()
            ->where('partner_id', $membership->partner_id)
            ->where('role', HostRole::OWNER)
            ->whereNotNull('accepted_at')
            ->count();

        if ($owners <= 1) {
            throw new DeskRefusal('A host always needs an owner. Make somebody else owner first.');
        }
    }
}
