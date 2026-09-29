<?php

namespace Tests\Feature;

use App\Filament\Host\Resources\Packages\Pages\CreatePackage;
use App\Filament\Host\Resources\Packages\Pages\EditPackage;
use App\Filament\Host\Resources\Packages\Pages\ListPackages;
use App\Filament\Resources\Packages\PackageResource as StaffPackageResource;
use App\Filament\Resources\Packages\Pages\ListPackages as StaffListPackages;
use App\Models\HostMembership;
use App\Models\Package;
use App\Models\Partner;
use App\Models\Property;
use App\Models\User;
use App\Support\Access;
use App\Support\HostRole;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Packages a host builds — §16 Phase 16.
 */
class HostPackagesTest extends TestCase
{
    use RefreshDatabase;

    private Partner $host;

    private Property $place;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = Partner::factory()->create(['name' => 'Coral Garden Inn']);
        $this->place = Property::factory()->create(['partner_id' => $this->host->id, 'name' => ['en' => 'Coral Garden']]);
        $this->owner = $this->member(HostRole::OWNER);
    }

    private function member(string $role, ?Partner $host = null): User
    {
        $user = User::factory()->create();
        HostMembership::create(['partner_id' => ($host ?? $this->host)->id, 'user_id' => $user->id, 'role' => $role, 'accepted_at' => now()]);

        return $user;
    }

    private function inPanel(?User $user = null): void
    {
        $this->actingAs($user ?? $this->owner);
        Filament::setCurrentPanel(Filament::getPanel('host'));
        Filament::getPanel('host')->boot();
        Filament::setTenant($this->host);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'title' => ['en' => 'A Fulidhoo weekend', 'dv' => ''],
            'summary' => ['en' => 'Two nights, the ferry and a sandbank picnic.', 'dv' => ''],
            'property_id' => $this->place->id,
            'nights' => 2,
            'sold_to' => 'both',
        ];
    }

    public function test_a_host_writes_a_package_and_it_is_theirs_an_island_holiday_and_not_live(): void
    {
        $this->inPanel();

        Livewire::actingAs($this->owner)->test(CreatePackage::class)
            ->fillForm($this->form())
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Package::sole();
        $this->assertSame($this->host->id, $package->partner_id);
        $this->assertSame(Package::ISLAND_HOLIDAY, $package->type);
        $this->assertFalse($package->is_published);
        $this->assertSame('a-fulidhoo-weekend', $package->slug);
        $this->assertSame('Draft', $package->hostStatus());
        $this->assertFalse($package->hasTranslation('title', 'dv'), 'An empty Dhivehi box is not a translation.');
    }

    public function test_a_host_cannot_build_on_somebody_elses_place(): void
    {
        $theirs = Property::factory()->create();
        $this->inPanel();

        Livewire::actingAs($this->owner)->test(CreatePackage::class)
            ->fillForm($this->form(['property_id' => $theirs->id]))
            ->call('create')
            ->assertHasFormErrors(['property_id']);

        $this->assertSame(0, Package::count());
    }

    public function test_sending_it_to_rihla_puts_it_on_the_staff_list(): void
    {
        $package = Package::factory()->islandHoliday()->create(['partner_id' => $this->host->id, 'property_id' => $this->place->id, 'is_published' => false]);
        // Still being written: not Rihla's to price yet.
        $draft = Package::factory()->islandHoliday()->create(['partner_id' => $this->host->id, 'property_id' => $this->place->id, 'is_published' => false]);
        $this->inPanel();

        Livewire::actingAs($this->owner)->test(ListPackages::class)
            ->callAction(TestAction::make('submit')->table($package));

        $this->assertNotNull($package->fresh()->submitted_at);
        $this->assertSame('With Rihla for pricing', $package->fresh()->hostStatus());

        $this->actingAs(User::factory()->create()->assignRole(Access::SUPER_ADMIN));
        Filament::setCurrentPanel(Filament::getPanel('staff'));
        $this->assertSame('1', StaffPackageResource::getNavigationBadge());

        Livewire::test(StaffListPackages::class)
            ->filterTable('from_hosts_waiting')
            ->assertCanSeeTableRecords([$package])
            ->assertCanNotSeeTableRecords([$draft])
            ->assertSee('Coral Garden Inn');
    }

    /** A page guests have booked from does not move under them. */
    public function test_a_live_package_is_the_hosts_to_read_not_to_change(): void
    {
        $live = Package::factory()->islandHoliday()->create(['partner_id' => $this->host->id, 'property_id' => $this->place->id, 'is_published' => true]);
        $this->inPanel();

        $this->get('/host/'.$this->host->slug.'/packages/'.$live->getRouteKey().'/edit')->assertForbidden();

        $draft = Package::factory()->islandHoliday()->create(['partner_id' => $this->host->id, 'property_id' => $this->place->id, 'is_published' => false]);
        $this->get('/host/'.$this->host->slug.'/packages/'.$draft->getRouteKey().'/edit')->assertOk();
    }

    /** Publishing is Rihla's: nothing a host sends can make a package live or change its kind. */
    public function test_an_edit_cannot_publish_or_retype_a_package(): void
    {
        $package = Package::factory()->islandHoliday()->create(['partner_id' => $this->host->id, 'property_id' => $this->place->id, 'is_published' => false, 'slug' => 'kept-slug']);
        $this->inPanel();

        Livewire::actingAs($this->owner)->test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->fillForm($this->form(['is_published' => true, 'type' => Package::UMRAH, 'slug' => 'stolen']))
            ->call('save')
            ->assertHasNoFormErrors();

        $package->refresh();
        $this->assertFalse($package->is_published);
        $this->assertSame(Package::ISLAND_HOLIDAY, $package->type);
        $this->assertSame('kept-slug', $package->slug);
        $this->assertSame('A Fulidhoo weekend', $package->getTranslation('title', 'en'));
    }

    public function test_hosts_see_only_the_packages_they_wrote(): void
    {
        $mine = Package::factory()->islandHoliday()->create(['partner_id' => $this->host->id, 'property_id' => $this->place->id]);
        $rihlas = Package::factory()->islandHoliday()->create(['partner_id' => null, 'property_id' => $this->place->id]);
        $other = Partner::factory()->create();
        $theirs = Package::factory()->islandHoliday()->create(['partner_id' => $other->id]);
        $this->inPanel();

        Livewire::actingAs($this->owner)->test(ListPackages::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$rihlas, $theirs]);

        $this->assertFalse($this->owner->can('update', $rihlas), 'Rihla\'s package on their guesthouse is still Rihla\'s.');
    }

    /** No database constraint, so the model does what one would have. */
    public function test_a_deleted_hosts_packages_become_rihlas(): void
    {
        $other = Partner::factory()->create();
        $package = Package::factory()->islandHoliday()->create(['partner_id' => $other->id]);

        $other->delete();

        $this->assertNull($package->fresh()->partner_id);
    }

    public function test_reception_does_not_write_packages(): void
    {
        $this->actingAs($this->member(HostRole::RECEPTION))->get('/host/'.$this->host->slug.'/packages')->assertForbidden();
    }
}
