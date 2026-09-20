<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Traveller;
use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roles say who may act; this says who did.
 *
 * The Ministry of Islamic Affairs licenses Umrah operators and expects
 * auditable records of what each pilgrim was promised, so this is a
 * compliance requirement as much as an engineering one — and it has to exist
 * before bookings, payments and passport documents arrive, not after.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    private function trip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Seven Nights in Madinah',
            'slug' => 'seven-nights-madinah',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-08',
            'status' => 'upcoming',
            'is_published' => true,
        ], $overrides));
    }

    public function test_creating_a_record_is_logged_with_its_values(): void
    {
        $trip = $this->trip();

        $log = AuditLog::where('auditable_type', Trip::class)
            ->where('auditable_id', $trip->id)
            ->sole();

        $this->assertSame(AuditLog::CREATED, $log->event);
        $this->assertNull($log->old_values);
        // A translated column is logged per language, not as the JSON blob it
        // is stored as — otherwise adding a Dhivehi title and rewriting the
        // English one leave the same entry.
        $this->assertSame(['en' => 'Seven Nights in Madinah'], $log->new_values['title']);
    }

    public function test_an_update_records_both_sides_of_each_change(): void
    {
        $trip = $this->trip();

        $trip->update(['title' => 'Ten Nights in Madinah']);

        $log = AuditLog::where('event', AuditLog::UPDATED)->sole();

        $this->assertSame(['en' => 'Seven Nights in Madinah'], $log->old_values['title']);
        $this->assertSame(['en' => 'Ten Nights in Madinah'], $log->new_values['title']);

        // Only what actually changed.
        $this->assertSame(['title'], array_keys($log->new_values));
    }

    /**
     * After a delete there is nothing left to compare against, so the whole
     * record is kept. This is the event an audit trail exists for.
     */
    public function test_a_delete_keeps_the_whole_record(): void
    {
        $trip = $this->trip();
        $id = $trip->id;

        $trip->delete();

        $log = AuditLog::where('event', AuditLog::DELETED)->sole();

        $this->assertSame($id, $log->auditable_id);
        $this->assertSame(['en' => 'Seven Nights in Madinah'], $log->old_values['title']);
        $this->assertNull($log->new_values);
    }

    /**
     * A trail that records password hashes or session tokens turns the audit
     * table into the most valuable table in the database — and it is the one
     * most people are allowed to read.
     */
    public function test_secrets_are_never_written_to_the_log(): void
    {
        $user = User::factory()->create();

        $user->update(['password' => 'a-new-password', 'name' => 'Renamed']);

        $logs = AuditLog::where('auditable_type', User::class)->get();

        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            foreach ([$log->old_values ?? [], $log->new_values ?? []] as $values) {
                $this->assertArrayNotHasKey('password', $values);
                $this->assertArrayNotHasKey('remember_token', $values);
            }
        }

        // The change that is not a secret is still recorded.
        $this->assertTrue(
            $logs->contains(fn ($log) => ($log->new_values['name'] ?? null) === 'Renamed'),
        );
    }

    /**
     * A save that altered nothing of substance is not an event. Without this
     * the log fills with rows whose only content is a timestamp.
     */
    public function test_a_touch_that_changes_nothing_is_not_recorded(): void
    {
        $trip = $this->trip();

        AuditLog::query()->delete();

        $trip->touch();
        $trip->update(['title' => 'Seven Nights in Madinah']);

        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_actor_is_recorded(): void
    {
        $admin = $this->admin();

        AuditLog::query()->delete();

        $this->actingAs($admin)->post(route('admin.trips.store'), [
            'title' => 'Ramadan Umrah',
            'date_start' => '2026-03-01',
            'date_end' => '2026-03-08',
            'status' => 'upcoming',
        ]);

        $log = AuditLog::where('auditable_type', Trip::class)->latestFirst()->first();

        $this->assertNotNull($log, 'Creating a trip through the panel recorded nothing.');
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($admin->name, $log->user_name);
        $this->assertNotNull($log->ip_address);
    }

    /**
     * An audit record must outlive the account that produced it, or removing
     * a member of staff would erase the evidence of what they did.
     */
    public function test_the_log_survives_the_deletion_of_its_author(): void
    {
        // A second Super Admin, because the last one cannot be deleted — see
        // User::isTheLastSuperAdmin(). The point here is the record, not the
        // role.
        $this->admin();

        $admin = $this->admin();
        $name = $admin->name;

        $this->actingAs($admin);
        $trip = $this->trip();

        $log = AuditLog::where('auditable_type', Trip::class)->sole();
        $this->assertSame($admin->id, $log->user_id);

        $admin->delete();

        $log->refresh();

        $this->assertNull($log->user_id);
        $this->assertSame($name, $log->user_name);
        $this->assertSame($name, $log->actor);
    }

    /**
     * Auditing the audit log would mean one write describing the write that
     * just happened, without end.
     */
    public function test_the_log_does_not_audit_itself(): void
    {
        $this->trip();

        $this->assertSame(
            0,
            AuditLog::where('auditable_type', AuditLog::class)->count(),
            'The audit log recorded itself.',
        );
    }

    public function test_only_oversight_roles_may_read_the_log(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.audit.index'))
            ->assertOk();

        $this->actingAs(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER))
            ->get(route('admin.audit.index'))
            ->assertOk();

        $this->actingAs(User::factory()->create()->assignRole(Access::REPORTING))
            ->get(route('admin.audit.index'))
            ->assertOk();

        // Content Manager edits the site; overseeing who changed what is not
        // part of that job.
        $this->actingAs(User::factory()->create()->assignRole(Access::CONTENT_MANAGER))
            ->get(route('admin.audit.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }

    /**
     * A passport number is not a password — staff have to read one to do
     * their job — but copying it into the audit trail puts it in a table far
     * more people can read, for ever. The *fact* of the change is kept,
     * because losing that is what a trail exists to prevent; the number is
     * not.
     */
    public function test_identity_document_numbers_are_masked_in_the_log(): void
    {
        $traveller = Traveller::factory()->create(['passport_number' => 'A1234567']);
        $traveller->update(['passport_number' => 'B7654321']);

        $logs = AuditLog::where('auditable_type', Traveller::class)->get();

        $this->assertNotEmpty($logs);

        foreach ($logs as $log) {
            $encoded = json_encode([$log->old_values, $log->new_values]);

            $this->assertStringNotContainsString('A1234567', $encoded);
            $this->assertStringNotContainsString('B7654321', $encoded);
        }

        $update = $logs->firstWhere('event', AuditLog::UPDATED);
        $this->assertArrayHasKey('passport_number', $update->new_values,
            'The trail must still show that the passport number changed.');
    }

    /** Money and identity data are the records an audit trail is most for. */
    public function test_bookings_are_audited(): void
    {
        $booking = Booking::factory()->create();
        $booking->forceFill(['notes' => 'Wheelchair at Velana'])->save();

        $this->assertNotEmpty(
            AuditLog::where('auditable_type', Booking::class)->get(),
        );
    }

    /**
     * The list shows which fields changed, never their contents: a value can
     * hold a passport number or a customer's address.
     */
    public function test_the_viewer_lists_field_names_not_values(): void
    {
        $this->actingAs($this->admin());

        $this->trip(['summary' => 'CONFIDENTIAL-VALUE-MARKER']);

        $this->actingAs($this->admin())
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('Trip')
            ->assertDontSee('CONFIDENTIAL-VALUE-MARKER');
    }
}
