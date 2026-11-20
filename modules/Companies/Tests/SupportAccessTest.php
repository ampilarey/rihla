<?php

namespace Modules\Companies\Tests;

use App\Exceptions\DeskRefusal;
use App\Filament\Resources\Partners\Pages\EditPartner;
use App\Models\Partner;
use App\Models\User;
use App\Support\Access;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Companies\Filament\Pages\SupportAccessPage;
use Modules\Companies\Models\SupportGrant;
use Modules\Companies\Models\SupportVisit;
use Modules\Companies\Services\AddCompany;
use Modules\Companies\Services\SupportAccess;
use Tests\TestCase;

/**
 * Support access with consent (companies plan, Phase 5c): Rihla asks, the
 * company's Owner decides and for how long, Rihla can look and change
 * nothing, and every page it opens is listed for the company.
 */
class SupportAccessTest extends TestCase
{
    use RefreshDatabase;

    private Partner $coral;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->coral = app(AddCompany::class)('Coral Umrah', 'Aishath', 'coral@example.com');
        $this->owner = User::where('email', 'coral@example.com')->sole();
        $this->staff = User::factory()->create(['name' => 'Ibrahim at Rihla'])->assignRole(Access::SUPER_ADMIN);
    }

    private function panel(string $path = ''): string
    {
        return '/company/'.$this->coral->slug.$path;
    }

    public function test_rihla_asks_from_the_companys_record(): void
    {
        Livewire::actingAs($this->staff)->test(EditPartner::class, ['record' => $this->coral->id])
            ->callAction('askSupport', data: ['reason' => 'They asked why a payment does not show.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Asked');

        $grant = SupportGrant::query()->sole();
        $this->assertSame(SupportGrant::PENDING, $grant->state());
        $this->assertSame($this->staff->id, $grant->requested_by);
    }

    public function test_nobody_from_rihla_enters_until_the_owner_allows_it_and_then_only_looks(): void
    {
        $grant = app(SupportAccess::class)->ask($this->coral, $this->staff, 'A payment does not show.');

        // Asked is not allowed: a Super Admin is nobody in a company's panel.
        $this->assertNotSame(200, $this->actingAs($this->staff)->get($this->panel())->status());

        // The Owner allows it, for an hour, from their Support access page.
        $this->actingAs($this->owner)->get($this->panel('/support-access'))->assertOk()->assertSee('A payment does not show.');
        Filament::setTenant($this->coral);
        Livewire::actingAs($this->owner)->test(SupportAccessPage::class)
            ->callTableAction('allow', $grant, data: ['hours' => 1])
            ->assertNotified('Allowed');
        $this->assertSame(SupportGrant::LIVE, $grant->fresh()->state());

        // Now Rihla can look, and change nothing. A fresh copy of the user,
        // as each real request loads: the one above remembers its role from
        // before the grant.
        $staff = $this->staff->fresh();
        // And a session of its own: the Owner's, still holding their
        // password's fingerprint, would sign anybody else out.
        $this->flushSession();
        $this->actingAs($staff)->get($this->panel('/bookings'))->assertOk();
        $this->actingAs($staff)->get($this->panel('/bookings/create'))->assertForbidden();
        // The Owner's own screens are the Owner's.
        $this->actingAs($staff)->get($this->panel('/team'))->assertForbidden();

        // Every page it opened is written down for the company — the ones it
        // was refused too, which is what a company would want to know.
        $paths = SupportVisit::query()->where('support_grant_id', $grant->id)->where('user_id', $this->staff->id)->pluck('path')->all();
        $this->assertContains('/company/'.$this->coral->slug.'/bookings', $paths);
        $this->assertContains('/company/'.$this->coral->slug.'/bookings/create', $paths);
    }

    public function test_access_ends_when_its_time_is_up_or_the_owner_ends_it(): void
    {
        $support = app(SupportAccess::class);
        $grant = $support->ask($this->coral, $this->staff, 'Checking a list.');
        $support->grant($grant, $this->owner, 1);

        $this->actingAs($this->staff)->get($this->panel('/bookings'))->assertOk();

        $this->travel(61)->minutes();
        $this->assertNotSame(200, $this->actingAs($this->staff)->get($this->panel('/bookings'))->status());

        $again = $support->ask($this->coral, $this->staff, 'One more look.');
        $support->grant($again, $this->owner, 4);
        $support->revoke($again, $this->owner);
        $this->assertNotSame(200, $this->actingAs($this->staff)->get($this->panel('/bookings'))->status());
    }

    public function test_only_the_owner_decides_and_rihla_cannot_allow_itself(): void
    {
        $support = app(SupportAccess::class);
        $grant = $support->ask($this->coral, $this->staff, 'Please.');

        try {
            $support->grant($grant, $this->staff, 24);
            $this->fail('Rihla allowed itself in.');
        } catch (DeskRefusal) {
            $this->assertSame(SupportGrant::PENDING, $grant->fresh()->state());
        }

        $support->decline($grant, $this->owner);
        $this->assertSame(SupportGrant::DECLINED, $grant->fresh()->state());

        $this->expectException(DeskRefusal::class);
        $support->grant($grant->fresh(), $this->owner, 1);
    }
}
