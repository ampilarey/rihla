<?php

namespace Tests\Feature;

use App\Exceptions\DeskRefusal;
use App\Filament\Host\Pages\Messages;
use App\Filament\Host\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Stays\Pages\ViewStay;
use App\Models\Customer;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\Property;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayMessage;
use App\Models\User;
use App\Services\Stays\StayGatekeeper;
use App\Services\Stays\StayMessages;
use App\Support\Access;
use App\Support\HostRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The conversation about a stay — §16.11, §16 Phase 14.5.
 */
class StayMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Stay $stay;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->create();
        $property = Property::factory()->create(['partner_id' => $this->host->id, 'name' => ['en' => 'Coral Garden Inn']]);
        $this->stay = Stay::factory()->confirmed()->create([
            'property_id' => $property->id,
            'room_type_id' => RoomType::factory()->create(['property_id' => $property->id])->id,
            'customer_id' => Customer::factory()->create(['name' => 'Aishath Rasheed'])->id,
        ]);

        $this->owner = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $this->owner->id, 'role' => HostRole::OWNER, 'accepted_at' => now()]);
    }

    private function inPanel(?User $user = null): void
    {
        $this->actingAs($user ?? $this->owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    private function guestPosts(string $body)
    {
        app(StayGatekeeper::class)->open($this->stay->id);

        return $this->post(route('my-stay.messages', ['locale' => 'en']), ['body' => $body]);
    }

    public function test_the_guest_writes_the_host_reads_and_answers(): void
    {
        $this->guestPosts('We land at 15:20 — can you meet the speedboat?')->assertRedirect();

        $this->assertSame(1, Messages::unreadFor($this->host->id));

        // The host opens the booking: read, and the count clears.
        $this->inPanel();
        Livewire::actingAs($this->owner)
            ->test(ViewBooking::class, ['record' => $this->stay->getRouteKey()])
            ->assertSee('We land at 15:20')
            ->callAction('message', data: ['body' => 'Yes — Ibrahim will be at the jetty.']);

        $this->assertSame(0, Messages::unreadFor($this->host->id));
        $this->assertNotNull(StayMessage::where('sender', StayMessage::GUEST)->sole()->read_at);

        // The guest sees the answer, signed by the guesthouse.
        $this->get(route('my-stay.home', ['locale' => 'en']))
            ->assertSee('Yes — Ibrahim will be at the jetty.')
            ->assertSee('Coral Garden Inn');
        $this->assertNotNull(StayMessage::where('sender', StayMessage::HOST)->sole()->read_at, 'Opening the stay page is reading it.');
    }

    public function test_rihla_writes_into_the_same_conversation(): void
    {
        $this->guestPosts('Is breakfast halal?');

        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
        Livewire::actingAs($staff)
            ->test(ViewStay::class, ['record' => $this->stay->getRouteKey()])
            ->callAction('message', data: ['body' => 'It is — we checked with the host.']);

        $this->assertSame(StayMessage::RIHLA, StayMessage::latest('id')->first()->sender);
        $this->assertSame($staff->id, StayMessage::latest('id')->first()->sender_user_id);
        $this->assertSame(1, Messages::unreadFor($this->host->id), 'The guest\'s message is read by Rihla; Rihla\'s own is unread by the host.');
    }

    public function test_plain_text_and_a_length_limit(): void
    {
        $this->guestPosts(str_repeat('a', StayMessage::MAX_LENGTH + 1))->assertSessionHasErrors('body');

        $message = app(StayMessages::class)->post($this->stay, StayMessage::GUEST, '<script>alert(1)</script>Hello');
        $this->assertSame('alert(1)Hello', $message->body);

        $this->expectException(DeskRefusal::class);
        app(StayMessages::class)->post($this->stay, StayMessage::GUEST, '   ');
    }

    public function test_a_guest_cannot_flood_the_box(): void
    {
        app(StayGatekeeper::class)->open($this->stay->id);

        for ($i = 0; $i < 30; $i++) {
            $this->post(route('my-stay.messages', ['locale' => 'en']), ['body' => "Message {$i}"])->assertRedirect();
        }

        $this->post(route('my-stay.messages', ['locale' => 'en']), ['body' => 'One more'])->assertStatus(429);
        $this->assertSame(30, StayMessage::count());
    }

    public function test_the_host_sees_only_their_own_conversations_unread_first(): void
    {
        $other = Stay::factory()->create();
        app(StayMessages::class)->post($other, StayMessage::GUEST, 'For somebody else');

        $quiet = Stay::factory()->confirmed()->create(['property_id' => $this->stay->property_id, 'room_type_id' => $this->stay->room_type_id]);
        app(StayMessages::class)->post($quiet, StayMessage::GUEST, 'Old and read');
        StayMessage::query()->update(['read_at' => now()]);
        app(StayMessages::class)->post($this->stay, StayMessage::GUEST, 'New');

        $this->inPanel();
        Livewire::actingAs($this->owner)->test(Messages::class)
            ->assertCanSeeTableRecords([$this->stay, $quiet], inOrder: true)
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_no_session_no_messages(): void
    {
        $this->post(route('my-stay.messages', ['locale' => 'en']), ['body' => 'Hi'])->assertRedirect();
        $this->assertSame(0, StayMessage::count());
    }

    public function test_the_dashboard_counts_unread_messages(): void
    {
        $this->guestPosts('Hello');

        $this->actingAs($this->owner)->get('/host/'.$this->host->slug)->assertSee('Unread messages');
        $this->inPanel();
        $this->assertSame('1', Messages::getNavigationBadge());
    }
}
