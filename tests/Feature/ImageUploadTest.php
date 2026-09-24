<?php

namespace Tests\Feature;

use App\Models\GuideStep;
use App\Models\Media;
use App\Models\User;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    /** @return array<string, mixed> */
    private function step(array $overrides = []): array
    {
        return array_merge([
            'step_number' => 1,
            'title' => 'Ihram',
            'summary' => 'Enter the state of Ihram.',
            'is_published' => '1',
        ], $overrides);
    }

    public function test_a_guide_step_image_is_stored_and_converted(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post(route('admin.guide-steps.store'), $this->step([
                'image' => UploadedFile::fake()->image('ihram.jpg', 1600, 900),
            ]))
            ->assertSessionHasNoErrors();

        $step = GuideStep::sole();

        $this->assertNotNull($step->image_path, 'No image path was stored.');
        Storage::disk('public')->assertExists($step->image_path);

        // Re-encoded rather than stored as uploaded: WebP at a capped width is
        // the whole point of processing it.
        $this->assertStringEndsWith('.webp', $step->image_path);
    }

    /**
     * The filename is 'step_' . time() . '.webp'. Two images uploaded in the
     * same second therefore resolve to the same path, and the second silently
     * overwrites the first — leaving one step showing another step's picture.
     */
    public function test_two_guide_step_images_do_not_overwrite_each_other(): void
    {
        Storage::fake('public');

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.guide-steps.store'), $this->step([
                'step_number' => 1,
                'image' => UploadedFile::fake()->image('one.jpg', 800, 600),
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('admin.guide-steps.store'), $this->step([
                'step_number' => 2,
                'image' => UploadedFile::fake()->image('two.jpg', 800, 600),
            ]))
            ->assertSessionHasNoErrors();

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

        $this->actingAs($this->admin())
            ->post(route('admin.guide-steps.store'), $this->step([
                'image' => UploadedFile::fake()->create('payload.php', 16, 'application/x-php'),
            ]))
            ->assertSessionHasErrors('image');

        $this->assertSame(0, GuideStep::count());
    }
}
