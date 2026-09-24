<?php

namespace Tests\Feature;

use App\Filament\Pages\SocialSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\Access;
use App\Support\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Social links and the contact number in the staff panel — §9.2.
 *
 * Who may reach it is in `AuthorizationTest`; this is what the form accepts
 * and what a save does to the one row every page reads.
 */
class SocialSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create()->assignRole(Access::OPERATIONS_MANAGER);
    }

    public function test_it_opens_with_what_is_stored(): void
    {
        Setting::setSocialSettings(['whatsapp_number' => '9601112222', 'instagram_url' => 'https://instagram.com/rihla']);

        Livewire::actingAs($this->manager())
            ->test(SocialSettings::class)
            ->assertFormSet([
                'whatsapp_number' => '9601112222',
                'instagram_url' => 'https://instagram.com/rihla',
                'facebook_url' => null,
            ]);
    }

    /** The number every WhatsApp and phone link dials, and the site's own. */
    public function test_saving_moves_every_contact_link(): void
    {
        Livewire::actingAs($this->manager())
            ->test(SocialSettings::class)
            ->fillForm(['whatsapp_number' => '9601112222', 'facebook_url' => 'https://facebook.com/rihla'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('https://wa.me/9601112222', Contact::whatsappUrl());
        $this->assertSame('https://facebook.com/rihla', Setting::getSocialSettings()['facebook_url']);
    }

    /**
     * Every key survives a save, because the row is one JSON blob replaced
     * whole — and the views read it with no fallback.
     */
    public function test_a_save_keeps_every_key(): void
    {
        Setting::setSocialSettings(['whatsapp_number' => '9607972434', 'youtube_playlist_id' => 'PLrealRihlaPlaylist']);

        Livewire::actingAs($this->manager())
            ->test(SocialSettings::class)
            ->fillForm(['facebook_url' => 'https://facebook.com/rihla'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = Setting::where('key', 'social')->sole()->value;

        $this->assertEqualsCanonicalizing(array_keys(Setting::socialDefaults()), array_keys($stored));
        $this->assertSame('PLrealRihlaPlaylist', $stored['youtube_playlist_id']);
    }

    public function test_the_number_is_required(): void
    {
        Livewire::actingAs($this->manager())
            ->test(SocialSettings::class)
            ->fillForm(['whatsapp_number' => ''])
            ->call('save')
            ->assertHasFormErrors(['whatsapp_number' => 'required']);
    }

    public function test_a_link_must_be_a_link(): void
    {
        Livewire::actingAs($this->manager())
            ->test(SocialSettings::class)
            ->fillForm(['facebook_url' => 'rihla on facebook'])
            ->call('save')
            ->assertHasFormErrors(['facebook_url' => 'url']);
    }

    /**
     * The placeholder playlist reached the live site once and a migration
     * took it out. The example beside the box must not put it back.
     */
    public function test_the_example_playlist_is_refused(): void
    {
        Livewire::actingAs($this->manager())
            ->test(SocialSettings::class)
            ->fillForm(['youtube_playlist_id' => SocialSettings::PLACEHOLDER_PLAYLIST])
            ->call('save')
            ->assertHasFormErrors(['youtube_playlist_id']);

        $this->assertNull(Setting::getSocialSettings()['youtube_playlist_id']);
    }

    public function test_the_old_address_forwards_to_the_new_one(): void
    {
        $this->actingAs($this->manager())->get('/admin/settings')->assertRedirect(SocialSettings::getUrl());
    }
}
