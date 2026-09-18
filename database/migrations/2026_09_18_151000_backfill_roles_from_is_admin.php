<?php

use App\Models\User;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Carry the `is_admin` boolean over to roles.
 *
 * Authorisation was a single flag: you were an admin or you were nothing.
 * That cannot express the nine staff functions Rihla actually has, and it
 * certainly cannot express customer-side access, which depends on a person's
 * relationship to a booking rather than on a role at all.
 *
 * The column is deliberately not dropped here. Until this has run against
 * production and the roles are confirmed, it is the only way back.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The roles have to exist before anyone can be given one, and a fresh
        // database runs migrations before seeders.
        $this->seedRoles();

        $role = Role::where('name', Access::SUPER_ADMIN)->where('guard_name', 'web')->first();

        if (! $role) {
            return;
        }

        DB::table('users')
            ->where('is_admin', true)
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($role) {
                foreach ($users as $user) {
                    DB::table('model_has_roles')->updateOrInsert([
                        'role_id' => $role->id,
                        'model_type' => User::class,
                        'model_id' => $user->id,
                    ]);
                }
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', Access::SUPER_ADMIN)->where('guard_name', 'web')->first();

        if (! $role) {
            return;
        }

        // Put the flag back for everyone the role was given to, so rolling
        // back does not lock the owner out of their own admin panel.
        $userIds = DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', User::class)
            ->pluck('model_id');

        DB::table('users')->whereIn('id', $userIds)->update(['is_admin' => true]);
    }

    private function seedRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        (new RolesAndPermissionsSeeder)->run();
    }
};
