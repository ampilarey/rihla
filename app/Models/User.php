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
use Spatie\Permission\Traits\HasRoles;

/**
 * The MustVerifyEmail contract was never declared, though everything it
 * governs already shipped: the email_verified_at column, the verification
 * routes, the verify-email view, and a test asserting the Verified event
 * fires. Without the interface that event carried a user the contract says it
 * cannot, and `verified` middleware would have waved anyone through unchecked.
 * The methods come from Authenticatable's trait, so only the marker was
 * missing.
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
}
