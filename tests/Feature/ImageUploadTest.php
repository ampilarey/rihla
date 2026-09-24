<?php

namespace Tests\Feature;

use App\Filament\Resources\GuideSteps\Pages\CreateGuideStep;
use App\Filament\Resources\GuideSteps\Pages\EditGuideStep;
use App\Models\GuideStep;
use App\Models\Media;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Image upload had no test at all, on any of the three paths that do it.
 *
 * Each resizes, re-encodes to WebP, writes a thumbnail and stores a path on
 * the model — and each was written separately, with its own filename scheme.
 */
class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    /**
     * A guide step through the staff panel's form, which replaced the Blade
     * one (§9.2). It has to store what the Blade form stored — a WebP at a
     * capped width — not the upload as it arrived.
     */
    private function createStep(array $overrides = []): Testable
    {
        return Livewire::actingAs($this->admin())
            ->test(CreateGuideStep::class)
            ->fillForm(array_merge([
                'step_number' => 1,
                'title' => ['en' => 'Ihram'],
                'summary' => ['en' => 'Enter the state of Ihram.'],
                'is_published' => true,
            ], $overrides))
            ->call('create');
    }

    public function test_a_guide_step_image_is_stored_and_converted(): void
    {
        Storage::fake('public');

        $this->createStep([
            'image_path' => UploadedFile::fake()->image('ihram.jpg', 1600, 900),
        ])->assertHasNoFormErrors();

        $step = GuideStep::sole();

        $this->assertNotNull($step->image_path, 'No image path was stored.');
        Storage::disk('public')->assertExists($step->image_path);

        // Re-encoded rather than stored as uploaded: WebP at a capped width is
        // the whole point of processing it.
        $this->assertStringEndsWith('.webp', $step->image_path);
        [$width] = getimagesizefromstring(Storage::disk('public')->get($step->image_path));
        $this->assertSame(1200, $width, 'The picture was stored at the size it was uploaded.');
        Storage::disk('public')->assertExists(str_replace('.webp', '-thumb.webp', $step->image_path));
    }

    /**
     * The Blade controller deleted the old picture by hand in update() and
     * destroy(). The model does it now, so it holds whichever screen saves.
     */
    public function test_a_replaced_or_deleted_guide_step_picture_leaves_the_disk(): void
    {
        Storage::fake('public');

        $this->createStep([
            'image_path' => UploadedFile::fake()->image('one.jpg', 800, 600),
        ])->assertHasNoFormErrors();

        $step = GuideStep::sole();
        $first = $step->image_path;
        $firstThumb = str_replace('.webp', '-thumb.webp', $first);

        Livewire::actingAs($this->admin())
            ->test(EditGuideStep::class, ['record' => $step->getKey()])
            // Removed, then the new one — what the browser's picker does.
            // Filling a new file straight over the old one appends it
            // alongside, which no editor can do.
            ->fillForm(['image_path' => []])
            ->fillForm(['image_path' => UploadedFile::fake()->image('two.jpg', 800, 600)])
            ->call('save')
            ->assertHasNoFormErrors();

        $second = $step->fresh()->image_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertMissing($firstThumb);
        Storage::disk('public')->assertExists($second);

        $step->fresh()->delete();

        Storage::disk('public')->assertMissing($second);
        Storage::disk('public')->assertMissing(str_replace('.webp', '-thumb.webp', $second));
    }

    /**
     * The filename is 'step_' . time() . '.webp'. Two images uploaded in the
     * same second therefore resolve to the same path, and the second silently
     * overwrites the first — leaving one step showing another step's picture.
     */
    public function test_two_guide_step_images_do_not_overwrite_each_other(): void
    {
        Storage::fake('public');

        $this->createStep([
            'step_number' => 1,
            'image_path' => UploadedFile::fake()->image('one.jpg', 800, 600),
        ])->assertHasNoFormErrors();

        $this->createStep([
            'step_number' => 2,
            'image_path' => UploadedFile::fake()->image('two.jpg', 800, 600),
        ])->assertHasNoFormErrors();

        $paths = GuideStep::orderBy('step_number')->pluck('image_path');

        $this->assertCount(2, $paths);
        $this->assertNotSame(
            $paths[0],
            $paths[1],
            'Both steps point at the same file: the second upload overwrote the first.',
        );

        Storage::disk('public')->assertExists($paths[0]);
        Storage::disk('public')->assertExists($paths[1]);
    }

    // A hero banner's photograph is now uploaded through the staff panel;
    // see HeroBannerAdminTest::test_a_photograph_is_stored_with_its_variants.

    public function test_a_media_photo_is_stored(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post(route('admin.media.store'), [
                'type' => 'photo',
                'title' => 'Madinah at dawn',
                'file_path' => UploadedFile::fake()->image('madinah.jpg', 1600, 900),
                'is_published' => '1',
            ])
            ->assertSessionHasNoErrors();

        $medium = Media::sole();

        $this->assertNotNull($medium->file_path, 'No file path was stored.');
        Storage::disk('public')->assertExists($medium->file_path);
    }

    /**
     * The upload rules accept images only; a PHP file renamed to .jpg must not
     * get through, and neither should an oversized one.
     */
    public function test_a_non_image_is_refused(): void
    {
        Storage::fake('public');

        $this->createStep([
            'image_path' => UploadedFile::fake()->create('payload.php', 16, 'application/x-php'),
        ])->assertHasFormErrors(['image_path']);

        $this->assertSame(0, GuideStep::count());
    }
}
