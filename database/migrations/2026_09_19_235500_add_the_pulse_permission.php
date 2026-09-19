<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `pulse.view`, which the `viewPulse` gate authorises against.
 *
 * Same reasoning as the `user.*` migration beside it: the seeder creates it
 * too, but a seeder only governs the next seed, and deploys run
 * `migrate --force` rather than `db:seed`.
 *
 * No role is granted it. Super Admin reaches it through `Gate::before`. If
 * this permission does not exist, `viewPulse` denies everyone except Super
 * Admin, which is the safe direction to fail.
 */
return new class extends Migration
{
    private const PERMISSION = 'pulse.view';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::PERMISSION, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
