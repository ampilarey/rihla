<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `attendance.*` and `opslog.*` and grant them.
 *
 * The Tour Leader takes the count and writes the day up: they are the one
 * standing at the coach door, and a head count that has to be entered by
 * the office is one taken from a photograph of a list. They cannot delete
 * a count — a count deleted from the coach is a count nobody can check.
 *
 * `attendance.delete` exists at all (unlike anything on incidents) because
 * a count started against the wrong departure is a mis-click with no
 * evidential value. A record of who was and was not on the coach is not the
 * same kind of thing as a record of what happened to somebody.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'attendance.viewAny', 'attendance.view', 'attendance.create',
        'attendance.update', 'attendance.delete',
        'opslog.viewAny', 'opslog.view', 'opslog.create', 'opslog.update',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Access::matrix() as $role => $permissions) {
            Role::where('name', $role)->first()
                ?->givePermissionTo(array_intersect($permissions, self::PERMISSIONS));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
