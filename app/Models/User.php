<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
