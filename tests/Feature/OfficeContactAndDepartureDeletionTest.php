<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\Bookings\Pages\EditBooking;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\Notice;
use App\Models\Traveller;
use App\Models\User;
use App\Support\Access;
use App\Support\OfficeContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 3a of docs/UMRAH_COMPANIES_PLAN.md, from Hijaz: a departure nobody
 * has booked may be deleted and one anybody has booked may not; and the
 * person the office deals with, who is often not the head of the family.
 */
class OfficeContactAndDepartureDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_a_departure_nobody_booked_can_be_deleted(): void
    {
        $departure = Departure::factory()->create();
        $manager = $this->staff(Access::OPERATIONS_MANAGER);

        $this->assertTrue($manager->can('delete', $departure));

        $departure->delete();
        $this->assertNull(Departure::find($departure->id));
    }

    public function test_a_departure_anybody_booked_is_cancelled_not_deleted_by_anyone(): void
    {
        $booking = Booking::factory()->create();
        $booking->forceFill(['status' => Booking::CANCELLED])->save();
        $departure = $booking->departure;

        // Even a cancelled booking keeps it: its money and history stay.
        $this->assertFalse($this->staff(Access::OPERATIONS_MANAGER)->can('delete', $departure));

        // The Super Admin passes every policy, so the model refuses too, in
        // words, rather than the database answering with an error page.
        $this->expectException(DeskRefusal::class);
        $this->expectExceptionMessage('Cancel it instead');
        $departure->delete();
    }

    public function test_the_office_contact_is_the_booker_until_somebody_else_is_named(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hassan Ahmed', 'phone' => '7771234']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        $contact = OfficeContact::forBooking($booking);
        $this->assertSame('Hassan Ahmed', $contact->name);
        $this->assertSame('7771234', $contact->phone);
        $this->assertSame('Hassan Ahmed on 7771234', $contact->words($customer));
    }

    public function test_a_family_contact_and_a_booking_contact_for_one_trip(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hassan Ahmed', 'phone' => '7771234']);
        $sister = Traveller::factory()->for($customer)->create(['full_name' => 'Aishath Ahmed', 'phone' => '7779999']);
        $father = Traveller::factory()->for($customer)->create(['full_name' => 'Ahmed Ali', 'phone' => null]);
        $customer->forceFill(['contact_traveller_id' => $sister->id])->save();

        $usual = Booking::factory()->create(['customer_id' => $customer->id]);
        $this->assertSame('Aishath Ahmed on 7779999', OfficeContact::forBooking($usual)->words($customer));

        // This trip's contact is the father, who has no phone of his own.
        $once = Booking::factory()->create(['customer_id' => $customer->id]);
        $once->forceFill(['contact_traveller_id' => $father->id])->save();
        $this->assertSame(
            'Ahmed Ali on 7771234 (the number of Hassan Ahmed, who booked; none on file for Ahmed Ali) · for this booking',
            OfficeContact::forBooking($once->fresh())->words($customer),
        );

        // Somebody who is not one of the booker's travellers is never used.
        $stranger = Traveller::factory()->create(['phone' => '7770000']);
        $usual->forceFill(['contact_traveller_id' => $stranger->id])->save();
        $this->assertSame('Aishath Ahmed', OfficeContact::forBooking($usual->fresh())->name);
    }

    public function test_the_family_button_names_the_contact_for_one_booking_or_for_every_one(): void
    {
        $customer = Customer::factory()->create(['name' => 'Hassan Ahmed', 'phone' => '7771234']);
        $sister = Traveller::factory()->for($customer)->create(['full_name' => 'Aishath Ahmed', 'phone' => '7779999']);
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);
        $line = $booking->travellers()->create(['traveller_id' => $sister->id, 'occupancy' => 'quad', 'is_lead' => true]);

        $page = Livewire::actingAs($this->staff(Access::BOOKING_STAFF))
            ->test(EditBooking::class, ['record' => $booking->getRouteKey()]);

        $page->callAction('family', [
            'head' => $line->id,
            'contact' => $sister->id,
            'contact_for_family' => false,
            'people' => [$line->id => ['relationship' => 'sibling', 'phone' => '7779999']],
        ])->assertHasNoActionErrors();

        $this->assertSame($sister->id, $booking->fresh()?->contact_traveller_id);
        $this->assertNull($customer->fresh()?->contact_traveller_id, 'For this booking only.');

        $page->callAction('family', [
            'head' => $line->id,
            'contact' => $sister->id,
            'contact_for_family' => true,
            'people' => [$line->id => ['relationship' => 'sibling', 'phone' => '7779999']],
        ])->assertHasNoActionErrors();

        $this->assertSame($sister->id, $customer->fresh()?->contact_traveller_id);
        $this->assertNull($booking->fresh()?->contact_traveller_id, 'The family\'s contact now, not a one-off.');
    }

    public function test_a_notice_about_a_booking_opens_whatsapp_with_its_office_contact(): void
    {
        $customer = Customer::factory()->create(['phone' => '7771234']);
        $sister = Traveller::factory()->for($customer)->create(['phone' => '+960 777 9999']);
        $customer->forceFill(['contact_traveller_id' => $sister->id])->save();
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);

        $notice = Notice::raise($booking, Notice::KINDS[0], 'Passport needed', null);

        $this->assertStringStartsWith('https://wa.me/9607779999?', (string) $notice?->whatsappUrl());
    }
}
