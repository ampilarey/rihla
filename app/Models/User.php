<?php

namespace App\Models;

use App\Exceptions\LastSuperAdmin;
use App\Support\Access;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * The MustVerifyEmail contract was never declared, though everything it
 * governs already shipped: the email_verified_at column, the verification
 * routes, the verify-email view, and a test asserting the Verified event
 * fires. Without the interface that event carried a user the contract says it
 * cannot, and `verified` middleware would have waved anyone through unchecked.
 * The methods come from Authenticatable's trait, so only the marker was
 * missing.
 *
 * The `encrypted:array` cast gives Larastan an empty array shape, which is
 * true of an empty list and useless for anything else — hence the explicit
 * annotation. §10.4's second factor:
 *
 * @property ?string $mfa_secret
 * @property ?Carbon $mfa_confirmed_at
 * @property ?list<string> $mfa_recovery_codes
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        // Deprecated: superseded by roles. Read only by the migration
        // that backfilled Super Admin from it, and kept for one release
        // so that backfill remains reversible. Nothing else may read it.
        'is_admin',
    ];

    /**
     * Whether this user may open the staff panel at /staff.
     *
     * Being signed in is not enough, and must not become enough: every
     * customer account Phase 3 introduces will be an authenticated user.
     * The same `admin.access` permission gates the Blade panel, so a staff
     * member's reach does not depend on which panel they happen to open.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->can('admin.access');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    /**
     * The last Super Admin cannot be deleted.
     *
     * Not a policy rule: `Gate::before` grants Super Admin every ability
     * before a policy method runs, so a guard written there would never fire
     * for the only people who can delete staff accounts. Here it holds for
     * the staff screen, the profile page's "delete my account" and a careless
     * tinker session alike.
     *
     * And not a `deleting` listener either, which is where this started.
     * `HasRoles` registers its own `deleting` listener to detach the pivot
     * rows, and trait boot runs before `booted()` — so by the time the guard
     * ran, the user it was checking no longer had any roles and it waved the
     * deletion through. It only appeared to work when something had loaded
     * the relation first. Overriding delete() runs before any of that.
     *
     * A mass delete (`User::where(...)->delete()`) fires no model events at
     * all and is not covered; nothing in this application does that.
     */
    public function delete(): ?bool
    {
        if ($this->isTheLastSuperAdmin()) {
            throw new LastSuperAdmin;
        }

        return parent::delete();
    }

    public function isTheLastSuperAdmin(): bool
    {
        if (! $this->hasRole(Access::SUPER_ADMIN)) {
            return false;
        }

        return static::role(Access::SUPER_ADMIN)->whereKeyNot($this->getKey())->doesntExist();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            // Ciphertext under APP_KEY in the column. A shared-host
            // database dump is the realistic threat (ADR 0002), and a TOTP
            // secret in a dump is a permanent second factor for whoever
            // reads it.
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
        ];
    }

    /**
     * The public-facing profile for this account, when there is one.
     *
     * A tour leader has one; finance does not. The Tour Leader Portal uses
     * it to answer "which groups are mine", and an account with no profile
     * is shown no groups at all — the failure mode of a missing link has to
     * be less access, never more.
     *
     * @return HasOne<Person, $this>
     */
    public function person(): HasOne
    {
        return $this->hasOne(Person::class);
    }

    /**
     * Whether this account has a second factor that actually works.
     *
     * A secret with no confirmation means somebody started enrolling and
     * wandered off. Treating that as protection would lock them out of
     * their own account with a code nothing can mint.
     */
    public function hasSecondFactor(): bool
    {
        return filled($this->mfa_secret) && $this->mfa_confirmed_at !== null;
    }

    /**
     * Whether this account's role obliges it to have one — §10.4.
     *
     * Obliges, not blocks: somebody in a required role with no factor is
     * sent to enrol, which they can always complete.
     */
    public function mustHaveSecondFactor(): bool
    {
        if (config('mfa.enforce') !== true) {
            return false;
        }

        return $this->hasAnyRole((array) config('mfa.required_roles', []));
    }

    /**
     * Fresh recovery codes, returned in the clear exactly once.
     *
     * Stored hashed, for the same reason a password is: a database dump
     * must not hand somebody eight working second factors. The plaintext
     * is shown at enrolment and never again, and the caller is responsible
     * for saying so.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < (int) config('mfa.recovery_codes', 8); $i++) {
            // Grouped and unambiguous: somebody reads these off paper.
            $code = strtoupper(bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)));
            $plain[] = $code;
            $hashed[] = hash('sha256', $code);
        }

        $this->forceFill(['mfa_recovery_codes' => $hashed])->save();

        return $plain;
    }

    /**
     * Spend a recovery code, if it is one.
     *
     * Single use: it is removed as it is accepted. A recovery code that
     * still works after it has been used is a password somebody has
     * written on paper.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = (array) ($this->mfa_recovery_codes ?? []);
        $candidate = hash('sha256', strtoupper(trim($code)));

        foreach ($codes as $index => $stored) {
            if (hash_equals((string) $stored, $candidate)) {
                unset($codes[$index]);
                $this->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }
}
