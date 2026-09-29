<?php

namespace Tests\Feature;

use App\Exceptions\ReviewRefused;
use App\Filament\Host\Resources\Reviews\Pages\ListReviews as HostReviews;
use App\Filament\Resources\Reviews\Pages\ListReviews as StaffReviews;
use App\Filament\Resources\Stays\Pages\ViewStay;
use App\Models\Customer;
use App\Models\HostMembership;
use App\Models\HostPage;
use App\Models\Notice;
use App\Models\Partner;
use App\Models\Property;
use App\Models\Review;
use App\Models\ReviewInvitation;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Notices\Sweep;
use App\Services\Stays\Reviews;
use App\Services\Stays\StayGatekeeper;
use App\Support\Access;
use App\Support\HostRole;
use App\Support\Services;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Guests' reviews, end to end — §16.11, §16 Phase 14.5.
 */
class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        Services::save(['stays_guesthouses' => Services::ON]);

        $this->host = Partner::factory()->create(['name' => 'Coral Garden Inn']);
        $this->property = Property::factory()->create(['partner_id' => $this->host->id, 'name' => ['en' => 'The Garden House']]);
    }

    private function stay(string $status = Stay::COMPLETED, array $attributes = []): Stay
    {
        return Stay::factory()->create($attributes + [
            'property_id' => $this->property->id,
            'room_type_id' => RoomType::factory()->create(['property_id' => $this->property->id])->id,
            'customer_id' => Customer::factory()->create(['name' => 'Aishath Rasheed'])->id,
            'status' => $status,
            'checked_out_at' => $status === Stay::COMPLETED ? now()->subDays(2) : null,
        ]);
    }

    // ── Writing one ──────────────────────────────────────────────────────

    public function test_a_guest_reviews_their_stay_and_it_goes_public_after_the_delay(): void
    {
        $stay = $this->stay();
        app(StayGatekeeper::class)->open($stay->id);

        $this->post(route('my-stay.review', ['locale' => 'en']), [
            'rating' => 4,
            'cleanliness' => 5,
            'body' => 'The sandbank trip was the best day of our holiday.',
        ])->assertRedirect(route('my-stay.home', ['locale' => 'en']));

        $review = Review::sole();
        $this->assertSame(4, $review->rating);
        $this->assertSame(5, $review->cleanliness);
        $this->assertSame($this->host->id, $review->partner_id, 'The host comes from the stay, not the request.');
        $this->assertFalse($review->isVisible(), 'Not public until the delay has passed.');

        $this->get('/en/stays/'.$this->property->slug)->assertDontSee('The sandbank trip');

        $this->travel((int) config('stays.reviews.publish_after_hours') + 1)->hours();

        $this->get('/en/stays/'.$this->property->slug)
            ->assertSee('The sandbank trip was the best day of our holiday.')
            ->assertSee('Aishath')
            ->assertDontSee('Rasheed', false);
    }

    /** The plan's plant: a stay that never reached `completed` is refused. */
    public function test_a_stay_that_did_not_happen_cannot_be_reviewed(): void
    {
        foreach ([Stay::CONFIRMED, Stay::CHECKED_IN, Stay::CANCELLED] as $status) {
            try {
                app(Reviews::class)->submit($this->stay($status), ['rating' => 5]);
                $this->fail("A {$status} stay was reviewed.");
            } catch (ReviewRefused) {
            }
        }

        $this->assertSame(0, Review::count());
    }

    /** The plan's plant: a second review on the same stay is refused. */
    public function test_one_review_per_stay(): void
    {
        $stay = $this->stay();
        app(Reviews::class)->submit($stay, ['rating' => 5]);

        $this->expectException(ReviewRefused::class);
        app(Reviews::class)->submit($stay->fresh(), ['rating' => 1]);
    }

    /** Even past the check, the unique key holds. */
    public function test_the_database_refuses_a_second_review_too(): void
    {
        $stay = $this->stay();
        Review::factory()->create(['stay_id' => $stay->id]);

        $this->expectException(QueryException::class);
        Review::factory()->create(['stay_id' => $stay->id]);
    }

    // ── Hiding ───────────────────────────────────────────────────────────

    /** The plan's plant: hiding removes it from the average. */
    public function test_a_hidden_review_counts_for_nothing_and_its_author_is_told_why(): void
    {
        Review::factory()->create(['stay_id' => $this->stay()->id, 'rating' => 5]);
        $bad = Review::factory()->create(['stay_id' => ($stay = $this->stay())->id, 'rating' => 1, 'body' => 'Rude words about a named person.']);

        $this->assertSame(['average' => 3.0, 'count' => 2], $this->property->rating());

        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
        Livewire::actingAs($staff)
            ->test(StaffReviews::class)
            ->callAction(TestAction::make('hide')->table($bad), data: ['reason' => null])
            ->assertHasActionErrors(['reason' => 'required']);

        Livewire::actingAs($staff)
            ->test(StaffReviews::class)
            ->callAction(TestAction::make('hide')->table($bad), data: ['reason' => 'It names a member of staff.']);

        $this->assertSame(['average' => 5.0, 'count' => 1], $this->property->fresh()->rating());
        $this->get('/en/stays/'.$this->property->slug)->assertDontSee('Rude words');

        // Search counts through its own query — the same rule, asked again.
        $this->get('/en/stays')->assertSee('5.0 · 1 review')->assertDontSee('2 reviews');

        app(StayGatekeeper::class)->open($stay->id);
        $this->get(route('my-stay.home', ['locale' => 'en']))->assertSee('It names a member of staff.');
    }

    public function test_only_whoever_may_update_reviews_can_hide_one(): void
    {
        $this->assertTrue(User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER)->can('review.update'));
        $this->assertFalse(User::factory()->create()->assignRole(Access::CONTENT_MANAGER)->can('review.update'));
    }

    // ── The invitation link ──────────────────────────────────────────────

    public function test_an_invitation_link_opens_the_form_once(): void
    {
        $stay = $this->stay();
        $token = app(Reviews::class)->invite($stay);

        $this->get(route('stays.review', ['locale' => 'en', 'token' => $token]))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee(__('messages.Send my review'));

        $this->post(route('stays.review.store', ['locale' => 'en', 'token' => $token]), ['rating' => 5])->assertRedirect();

        $this->assertSame(5, Review::sole()->rating);

        // Spent: the page shows what was written, and the form is gone.
        $this->get(route('stays.review', ['locale' => 'en', 'token' => $token]))
            ->assertOk()
            ->assertDontSee(__('messages.Send my review'));

        $this->get(route('stays.review', ['locale' => 'en', 'token' => 'not-a-real-token']))->assertNotFound();

        $this->travel(31)->days();
        $this->get(route('stays.review', ['locale' => 'en', 'token' => $token]))->assertNotFound();
    }

    public function test_staff_mint_the_link_for_a_finished_stay_without_a_review(): void
    {
        $stay = $this->stay();
        $staff = User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);

        Livewire::actingAs($staff)
            ->test(ViewStay::class, ['record' => $stay->getRouteKey()])
            ->assertActionVisible('reviewLink')
            ->mountAction('reviewLink');

        $invitation = ReviewInvitation::sole();
        $this->assertTrue($invitation->stay->is($stay));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $invitation->token_hash, 'Only the hash is stored.');
    }

    // ── Asking ───────────────────────────────────────────────────────────

    public function test_the_sweep_asks_once_a_day_after_check_out_and_not_after_a_review(): void
    {
        $asked = $this->stay();
        $reviewed = $this->stay();
        Review::factory()->create(['stay_id' => $reviewed->id]);
        $tooSoon = $this->stay(Stay::COMPLETED, ['checked_out_at' => now()->subHours(3)]);

        app(Sweep::class)->run();
        app(Sweep::class)->run();

        $this->assertSame(1, Notice::where('kind', Notice::REVIEW_REQUESTED)->count());
        $notice = Notice::where('kind', Notice::REVIEW_REQUESTED)->sole();
        $this->assertTrue($notice->noticeable->is($asked));
        $this->assertStringContainsString('Aishath', $notice->headline);
        $this->assertStringNotContainsString('stays/review/', (string) $notice->body, 'A credential is never stored in a notice.');
    }

    // ── The host ─────────────────────────────────────────────────────────

    private function hostUser(string $role = HostRole::OWNER): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => $this->host->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    public function test_the_host_replies_once_and_may_correct_it_for_a_day(): void
    {
        $review = Review::factory()->create(['stay_id' => $this->stay()->id]);
        $owner = $this->hostUser();
        $this->inPanel($owner);

        Livewire::actingAs($owner)->test(HostReviews::class)
            ->callAction(TestAction::make('reply')->table($review), data: ['reply' => 'Thank you, come back soon!']);
        Livewire::actingAs($owner)->test(HostReviews::class)
            ->callAction(TestAction::make('reply')->table($review->fresh()), data: ['reply' => 'Thank you — come back soon!']);

        $this->assertSame('Thank you — come back soon!', $review->fresh()->host_reply);

        $this->travel(25)->hours();

        $this->assertFalse($review->fresh()->replyIsEditable());
        $this->expectException(ReviewRefused::class);
        app(Reviews::class)->reply($review->fresh(), 'Too late.');
    }

    public function test_a_host_sees_only_their_own_reviews_and_reception_none(): void
    {
        $mine = Review::factory()->create(['stay_id' => $this->stay()->id]);
        $theirs = Review::factory()->create();

        $owner = $this->hostUser();
        $this->inPanel($owner);

        Livewire::actingAs($owner)->test(HostReviews::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);

        $reception = $this->hostUser(HostRole::RECEPTION);
        $this->actingAs($reception)->get('/host/'.$this->host->slug.'/reviews')->assertForbidden();
    }

    // ── Where the rating shows ───────────────────────────────────────────

    public function test_the_rating_shows_on_search_and_on_the_hosts_page(): void
    {
        HostPage::factory()->published()->create(['partner_id' => $this->host->id]);
        Review::factory()->create(['stay_id' => $this->stay()->id, 'rating' => 4]);

        $this->get('/en/stays')->assertSee('4.0');
        $this->get('/en/stays/hosts/'.$this->host->slug)->assertSee('4.0')->assertSee('Lovely family, spotless room.');
    }

    public function test_best_rated_sorts_reviewed_listings_first(): void
    {
        $unreviewed = Property::factory()->create(['partner_id' => $this->host->id, 'name' => ['en' => 'Aaa Nobody Reviewed']]);
        RoomType::factory()->create(['property_id' => $unreviewed->id]);
        Review::factory()->create(['stay_id' => $this->stay()->id, 'rating' => 3]);

        $this->get('/en/stays?sort=rating')->assertSeeInOrder(['The Garden House', 'Aaa Nobody Reviewed']);
    }
}
