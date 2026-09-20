<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `knowledge.*`, and the Scholar role that §6.4 requires.
 *
 * The role is created here rather than left to the seeder because the
 * seeder has already run on every existing install: a role that only
 * appears on a fresh database is one production never gets.
 *
 * ## Why review and publish are different permissions
 *
 * §6.4: "editorial review before publish is non-negotiable for religious
 * content." A reviewer who also holds the publish button is not a
 * reviewer — the check becomes a formality the same person performs on
 * themselves.
 *
 * So the Scholar reviews and cannot publish; Operations publishes and
 * cannot review; the Content Manager drafts and does neither. Owning the
 * website's words is not enough to sign off religious content.
 *
 * Super Admin still reaches everything through `Gate::before`, which is the
 * deliberate design recorded elsewhere. The protection here is that no
 * *other* account can hold both halves, and that the screens offer neither
 * without the permission.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'knowledge.viewAny', 'knowledge.view', 'knowledge.create',
        'knowledge.update', 'knowledge.review', 'knowledge.publish',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // The role itself, for installs whose seeder ran before it existed.
        Role::findOrCreate(Access::SCHOLAR, 'web');

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

        // The role goes only if nobody holds it. Removing a role out from
        // under a live account would silently strip somebody's access, and
        // a rollback is not a reason to do that.
        $scholar = Role::where('name', Access::SCHOLAR)->where('guard_name', 'web')->first();

        if ($scholar !== null && $scholar->users()->count() === 0) {
            $scholar->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
