<?php

namespace Tests\Feature;

use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Pages\CreateMedia;
use App\Filament\Resources\Media\Pages\EditMedia;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Models\Media;
use App\Models\Trip;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The gallery in the staff panel — §9.2.
 *
 * The write paths the Blade screen's tests covered moved with it, into
 * `ImageUploadTest`, `MediaTranslationTest`, `AdminWritePathTest` and
 * `InlineHandlerTest`. This file holds what `MediaRequest` enforced and
 * nothing else checked: which fields each type needs, and on which
 * operation.
 */
class MediaAdminTest extends TestCase
{
    use RefreshDatabase;

    private function contentManager(): User
    {
        return User::factory()->create()->assignRole(Access::CONTENT_MANAGER);
    }

    private function trip(): Trip
    {
        return Trip::create([
            'title' => ['en' => 'Ramadan Umrah'],
            'slug' => 'ramadan-umrah',
            'date_start' => now()->addMonth(),
            'date_end' => now()->addMonth()->addDays(10),
            'status' => Trip::STATUS_UPCOMING,
            'is_published' => true,
        ]);
    }

    public function test_a_video_needs_its_link(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateMedia::class)
            ->fillForm(['type' => 'video', 'video_url' => ''])
            ->call('create')
            ->assertHasFormErrors(['video_url' => 'required']);

        $this->assertSame(0, Media::count());
    }

    public function test_a_new_photo_needs_its_file(): void
    {
        Livewire::actingAs($this->contentManager())
            ->test(CreateMedia::class)
            ->fillForm(['type' => 'photo'])
            ->call('create')
            ->assertHasFormErrors(['file_path' => 'required']);

        $this->assertSame(0, Media::count());
    }

    /** Changing a caption must not demand the photograph again. */
    public function test_editing_a_photo_does_not_ask_for_the_file_again(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/large/x.webp', 'large');
        Storage::disk('public')->put('media/thumbs/x.webp', 'thumb');

        $medium = Media::create([
            'type' => 'photo',
            'title' => ['en' => 'Madinah at dawn'],
            'file_path' => 'media/large/x.webp',
            'thumb_path' => 'media/thumbs/x.webp',
            'is_published' => true,
        ]);

        Livewire::actingAs($this->contentManager())
            ->test(EditMedia::class, ['record' => $medium->getKey()])
            ->fillForm(['caption' => ['en' => 'After Fajr.']])
            ->call('save')
            ->assertHasNoFormErrors();

        $medium->refresh();

        $this->assertSame('After Fajr.', $medium->getTranslation('caption', 'en'));
        $this->assertSame('media/large/x.webp', $medium->file_path);
        $this->assertSame('media/thumbs/x.webp', $medium->thumb_path, 'Saving without a new photo lost the thumbnail.');
        Storage::disk('public')->assertExists(['media/large/x.webp', 'media/thumbs/x.webp']);
    }

    /** The trip screen's "Add media" link names its trip. */
    public function test_adding_from_a_trip_starts_with_that_trip(): void
    {
        $trip = $this->trip();

        $this->actingAs($this->contentManager())
            ->get(MediaResource::getUrl('create', ['trip_id' => $trip->id]))
            ->assertOk();

        Livewire::withQueryParams(['trip_id' => $trip->id])
            ->actingAs($this->contentManager())
            ->test(CreateMedia::class)
            ->assertFormSet(['trip_id' => $trip->id]);
    }

    public function test_the_list_shows_items_with_their_trip(): void
    {
        $trip = $this->trip();
        Media::create(['type' => 'video', 'title' => ['en' => 'Highlights'], 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'trip_id' => $trip->id, 'is_published' => true]);

        Livewire::actingAs($this->contentManager())
            ->test(ListMedia::class)
            ->assertCountTableRecords(1)
            ->assertSeeText('Highlights')
            ->assertSeeText('Ramadan Umrah');
    }

    public function test_the_old_addresses_forward_to_the_new_one(): void
    {
        $medium = Media::create(['type' => 'photo', 'title' => ['en' => 'A photo'], 'is_published' => true]);

        foreach (['/admin/media', '/admin/media/create', "/admin/media/{$medium->id}", "/admin/media/{$medium->id}/edit"] as $old) {
            $this->actingAs($this->contentManager())->get($old)->assertRedirect(MediaResource::getUrl('index'));
        }
    }
}
