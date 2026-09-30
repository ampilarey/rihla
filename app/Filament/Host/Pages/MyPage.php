<?php

namespace App\Filament\Host\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Http\Controllers\HostPageController;
use App\Http\Middleware\SetLocale;
use App\Models\HostPage;
use App\Models\Partner;
use App\Models\User;
use App\Support\Brand;
use App\Support\Contrast;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The host's own page on Rihla — §16.8.
 *
 * Branding within a frame: colours are checked for contrast before they
 * are saved, the font is one of three the site already has, and the
 * sections are switched on or off rather than arranged. Rihla's header,
 * footer and trust line are not the host's to change.
 *
 * @property-read Schema $form
 */
class MyPage extends Page
{
    use EditsTranslations;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paint-brush';

    protected static ?string $navigationLabel = 'My page';

    protected static ?string $title = 'My page';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'my-page';

    protected string $view = 'filament.pages.services';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::MY_PAGE);
    }

    public function mount(): void
    {
        $page = $this->record();

        $this->form->fill([
            'layout' => $page->layout,
            'logo_path' => $page->logo_path,
            'cover_path' => $page->cover_path,
            'colour_primary' => $page->colour_primary,
            'colour_accent' => $page->colour_accent,
            'font' => $page->font,
            'tagline' => $page->getTranslations('tagline'),
            'about' => $page->getTranslations('about'),
            'sections' => $page->sections ?? array_keys(HostPage::SECTIONS),
            'faq' => $page->faq ?? [],
            'whatsapp' => $page->whatsapp,
            'instagram' => $page->instagram,
            'facebook' => $page->facebook,
            'website_url' => $page->website_url,
        ]);
    }

    public function getSubheading(): ?string
    {
        $page = $this->record();

        return match (true) {
            ! $this->host()->isListed() => 'Your page goes live once Rihla has checked your account. You can build it now.',
            $page->isPublished() => 'Published — guests can see it.',
            default => 'Not published yet. Preview it, then publish when it is ready.',
        };
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('How it looks')
                    ->columns(2)
                    ->schema([
                        Radio::make('layout')
                            ->options([
                                HostPage::STORY => 'Story first — your cover, your story, then your places',
                                HostPage::GRID => 'Places first — your places, then everything else',
                            ])
                            ->required(),
                        Select::make('font')
                            ->options(array_map(fn (array $font): string => $font['label'], HostPage::FONTS))
                            ->required(),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('public')
                            ->directory('host-pages')
                            ->maxSize(2048),
                        FileUpload::make('cover_path')
                            ->label('Cover photograph')
                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('public')
                            ->directory('host-pages')
                            ->maxSize(5120)
                            ->helperText('Wide works best. It is also the picture a shared link shows.'),
                        ColorPicker::make('colour_primary')
                            ->label('Headings')
                            ->regex('/^#[0-9A-Fa-f]{6}$/')
                            ->rules([self::readableOnPage()])
                            ->helperText('Left empty, headings use Rihla\'s colour. Must be readable on the page.'),
                        ColorPicker::make('colour_accent')
                            ->label('Buttons')
                            ->regex('/^#[0-9A-Fa-f]{6}$/')
                            ->live()
                            ->rules([self::buttonReadable()])
                            ->helperText(fn (Get $get): string => filled($get('colour_accent'))
                                ? 'The words on your buttons will be '.(HostPage::textOn((string) $get('colour_accent')) === '#FFFFFF' ? 'white' : 'dark').', whichever reads.'
                                : 'Left empty, buttons use Rihla\'s colour.'),
                    ]),

                Section::make('Your words')
                    ->description('English is the one every guest can fall back to. Write it first.')
                    ->schema([
                        Tabs::make('Languages')
                            ->tabs(array_map(fn (string $locale): Tab => Tab::make(PropertyForm::LOCALES[$locale])->schema([
                                TextInput::make("tagline.{$locale}")
                                    ->label('Tagline')
                                    ->maxLength(140)
                                    ->extraInputAttributes(SetLocale::isRtl($locale) ? ['dir' => 'rtl'] : []),
                                Textarea::make("about.{$locale}")
                                    ->label('Your story')
                                    ->rows(6)
                                    ->maxLength(4000)
                                    ->extraInputAttributes(SetLocale::isRtl($locale) ? ['dir' => 'rtl'] : []),
                            ]), SetLocale::SUPPORTED))
                            ->columnSpanFull(),
                    ]),

                Section::make('What the page shows')
                    ->schema([
                        CheckboxList::make('sections')
                            ->options(HostPage::SECTIONS)
                            ->columns(3)
                            ->helperText('Your places are always shown. Reviews appear by themselves once guests leave them.'),
                        Repeater::make('faq')
                            ->label('Questions and answers')
                            ->maxItems(12)
                            ->collapsible()
                            ->schema([
                                TextInput::make('question.en')->label('Question (English)')->required()->maxLength(200),
                                Textarea::make('answer.en')->label('Answer (English)')->required()->rows(2)->maxLength(1000),
                                TextInput::make('question.dv')->label('Question (Dhivehi)')->maxLength(200)->extraInputAttributes(['dir' => 'rtl']),
                                Textarea::make('answer.dv')->label('Answer (Dhivehi)')->rows(2)->maxLength(1000)->extraInputAttributes(['dir' => 'rtl']),
                                TextInput::make('question.ar')->label('Question (Arabic)')->maxLength(200)->extraInputAttributes(['dir' => 'rtl']),
                                Textarea::make('answer.ar')->label('Answer (Arabic)')->rows(2)->maxLength(1000)->extraInputAttributes(['dir' => 'rtl']),
                            ]),
                    ]),

                Section::make('How guests reach you')
                    ->columns(2)
                    ->schema([
                        TextInput::make('whatsapp')->label('WhatsApp number')->tel()->maxLength(40),
                        TextInput::make('website_url')->label('Your own website')->url()->maxLength(255),
                        TextInput::make('instagram')->label('Instagram link')->url()->maxLength(255),
                        TextInput::make('facebook')->label('Facebook link')->url()->maxLength(255),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Save')->submit('save')];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => HostPageController::previewUrl($this->host()), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $this->record()->exists && $this->host()->isListed()),
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-globe-alt')
                ->visible(fn (): bool => $this->record()->exists && ! $this->record()->isPublished())
                ->requiresConfirmation()
                ->modalDescription('Guests can find your page as soon as Rihla has checked your account.')
                ->action(function (): void {
                    $this->record()->forceFill(['published_at' => now()])->save();
                    Notification::make()->success()->title('Published')->send();
                }),
            Action::make('unpublish')
                ->label('Take offline')
                ->icon('heroicon-o-eye-slash')
                ->color('gray')
                ->visible(fn (): bool => $this->record()->isPublished())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record()->forceFill(['published_at' => null])->save();
                    Notification::make()->success()->title('Taken offline')->send();
                }),
        ];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = self::withoutEmptyLocales($this->form->getState(), ['tagline', 'about']);
        $data['faq'] = array_values(array_map(fn (array $item): array => [
            'question' => array_filter((array) ($item['question'] ?? []), 'filled'),
            'answer' => array_filter((array) ($item['answer'] ?? []), 'filled'),
        ], (array) ($data['faq'] ?? [])));
        $data['font'] = array_key_exists((string) ($data['font'] ?? ''), HostPage::FONTS) ? $data['font'] : 'inter';
        $data['layout'] = in_array($data['layout'] ?? null, HostPage::LAYOUTS, true) ? $data['layout'] : HostPage::STORY;
        $data['sections'] = array_values(array_intersect((array) ($data['sections'] ?? []), array_keys(HostPage::SECTIONS)));

        $page = $this->record();
        $page->fill($data);
        $page->partner_id = $this->host()->getKey();
        $page->save();

        Notification::make()->success()->title('Saved')->send();
    }

    /** Headings must read on the page ground and on the white cards. */
    public static function readableOnPage(): Closure
    {
        // Wrapped: Filament evaluates a closure in `rules()` itself, and
        // the inner one is what Laravel's validator runs.
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            foreach ([Brand::CREAM, '#FFFFFF'] as $ground) {
                $ratio = Contrast::ratio(is_string($value) ? $value : null, $ground);

                if ($ratio !== null && $ratio < Contrast::AA) {
                    $fail(sprintf('Headings in this colour would be %.2f:1 against the page — too faint to read. Choose a darker colour (at least %.1f:1).', $ratio, Contrast::AA));

                    return;
                }
            }
        };
    }

    /** A button colour needs white words or dark words to read on it. */
    public static function buttonReadable(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $best = max(Contrast::ratio('#FFFFFF', $value) ?? 0, Contrast::ratio(Brand::INK, $value) ?? 0);

            if ($best < Contrast::AA) {
                $fail(sprintf('Neither white nor dark words read on this colour (best %.2f:1; at least %.1f:1 is needed). Choose a lighter or a darker one.', $best, Contrast::AA));
            }
        };
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }

    private function record(): HostPage
    {
        return HostPage::firstOrNew(['partner_id' => $this->host()->getKey()]);
    }
}
