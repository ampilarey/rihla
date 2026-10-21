<?php

namespace Tests\Feature;

use App\Support\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The public pages are written phone-first, so nothing may correct them at
 * phone width.
 *
 * `resources/css/app.css` used to carry a block of `max-width: 768px`
 * overrides, all `!important`. It forced `.grid` to one column — every photo
 * grid designed as two-up stacked one per row on a phone — pulled every
 * `.fixed` element in from the right, which left an 8px strip beside each
 * full-screen overlay, and stripped shadows and large text. None of it showed
 * in a test, because the markup was right and only the cascade was wrong.
 */
class MobileFirstLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function stylesheet(): string
    {
        return File::get(resource_path('css/app.css'));
    }

    public function test_the_stylesheet_never_overrides_a_layout_below_a_width(): void
    {
        $this->assertDoesNotMatchRegularExpression('/@media[^{]*max-width/', $this->stylesheet(),
            'A max-width override undoes the phone layout a page was written with. '
            .'Write the phone case as the default and widen it with min-width (sm:, md:, lg:).');
    }

    public function test_a_grid_keeps_the_columns_its_page_asks_for(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\.grid\s*\{[^}]*grid-template-columns/', $this->stylesheet(),
            'A rule on .grid overrides every grid-cols-* on the site.');
    }

    public function test_a_touch_screen_gets_sixteen_pixel_fields(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media\s*\(pointer:\s*coarse\)\s*\{.*?input, select, textarea\s*\{\s*font-size:\s*16px/s',
            $this->stylesheet(),
            'An iPhone zooms into any field whose text is under 16px.',
        );
    }

    public function test_the_floating_buttons_wait_for_the_top_bar_to_scroll_away(): void
    {
        $page = $this->get('/en/contact')->assertOk()->getContent();

        $this->assertStringContainsString('data-top-bar', $page);
        $this->assertStringContainsString('data-floating-contact', $page);
        $this->assertStringContainsString("document.documentElement.classList.add('js')", $page,
            'The stack starts hidden only when script will run to reveal it.');
        $this->assertMatchesRegularExpression('/\.js \[data-floating-contact\]:not\(\[data-shown\]\)/', $this->stylesheet());
    }

    public function test_the_guide_has_one_floating_whatsapp_button_and_it_asks_for_help_with_the_guide(): void
    {
        $page = $this->get('/en/guide')->assertOk()->getContent();

        $this->assertSame(1, substr_count($page, '<div data-floating-contact'));
        $this->assertStringContainsString(e(Contact::whatsappUrl('I need help with the Umrah guide')), $page);
    }

    public function test_any_other_page_opens_whatsapp_without_a_message(): void
    {
        $this->get('/en/contact')
            ->assertOk()
            ->assertSee('href="'.e(Contact::whatsappUrl()).'"', false);
    }

    /**
     * The guide's progress line on a phone read `${__('Step')} …` inside an
     * Alpine template literal. `__` is PHP's translator, not a function the
     * browser has, so the expression threw on every load and "Step 3 of 10"
     * never appeared. Nothing server-side could see it: the page rendered 200.
     */
    public function test_no_alpine_expression_calls_the_php_translator(): void
    {
        foreach (File::allFiles(resource_path('views')) as $view) {
            $this->assertDoesNotMatchRegularExpression('/\$\{\s*__\(/', $view->getContents(),
                "{$view->getRelativePathname()} calls __() inside a JavaScript template literal; "
                .'translate on the server with @js(__(...)) instead.');
        }
    }
}
