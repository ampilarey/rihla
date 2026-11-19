<?php

namespace App\Filament\Company\Pages;

use App\Filament\Concerns\EditsTranslations;
use App\Filament\Host\Pages\MyPage;
use App\Filament\Resources\Properties\Schemas\PropertyForm;
use App\Http\Middleware\SetLocale;
use App\Models\CompanySite;
use App\Models\HostPage;
use App\Models\Partner;
use App\Support\CompanyRole;
use App\Support\CurrentSite;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
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
 * The company's own website — companies plan, Phase 4a.
 *
 * Branding within a frame, as a host's page is: colours checked for
 * contrast before they are saved, a font the site already has, sections
 * switched on or off rather than arranged. The site goes live when Rihla's
 * admin has switched it on and the company has published it. The Owner
 * only.
 *
 * @property-read Schema $form
 */
class Website extends Page
{
    use EditsTranslations;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Website';

    protected static ?string $title = 'Website';

    protected static ?string $slug = 'website';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.services';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Partner
            && auth()->user()?->roleAtCompany($company) === CompanyRole::OWNER;
    }

    /** The address the site answers at, or null until the owner has set the domain. */
    public static function address(Partner $company): ?string
    {
        $host = CurrentSite::hostOf($company);

        return $host === null ? null : 'https://'.$host;
    }

    public function mount(): void
    {
        $site = $this->record();

        $this->form->fill([
            'theme' => $site->theme,
            'logo_path' => $site->logo_path,
            'cover_path' => $site->cover_path,
            'colour_primary' => $site->colour_primary,
            'colour_accent' => $site->colour_accent,
            'font' => $site->font,
            'tagline' => $site->getTranslations('tagline'),
            'about' => $site->getTranslations('about'),
            'sections' => $site->sections ?? array_keys(CompanySite::SECTIONS),
            'whatsapp' => $site->whatsapp,
            'instagram' => $site->instagram,
            'facebook' => $site->facebook,
        ]);
    }

    public function getSubheading(): ?string
    {
        $company = $this->company();
        $site = $this->record();
        $address = self::address($company);

        return match (true) {
            ! $company->site_enabled => 'Rihla switches your site on once your registration is complete. You can build it now.',
            ! $site->isPublished() => 'Not published yet. Publish it when it is ready.',
            $address === null => 'Published. Your address is being set up by Rihla.',
            default => 'Published at '.$address.'.',
        };
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('How it looks')
                    ->columns(2)
                    ->schema([
                        Radio::make('theme')->options(CompanySite::THEMES)->required()->columnSpanFull(),
                        Select::make('font')
                            ->options(array_map(fn (array $font): string => $font['label'], HostPage::FONTS))
                            ->required(),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('public')
                            ->directory('company-sites')
                            ->maxSize(2048),
                        FileUpload::make('cover_path')
                            ->label('Cover photograph')
                            ->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('public')
                            ->directory('company-sites')
                            ->maxSize(5120)
                            ->helperText('Wide works best. It is also the picture a shared link shows.'),
                        ColorPicker::make('colour_primary')
                            ->label('Headings')
                            ->regex('/^#[0-9A-Fa-f]{6}$/')
                            ->rules([MyPage::readableOnPage()])
                            ->helperText('Must be readable on a white page.'),
                        ColorPicker::make('colour_accent')
                            ->label('Header and buttons')
                            ->regex('/^#[0-9A-Fa-f]{6}$/')
                            ->live()
                            ->rules([MyPage::buttonReadable()])
                            ->helperText(fn (Get $get): string => filled($get('colour_accent'))
                                ? 'The words on it will be '.(HostPage::textOn((string) $get('colour_accent')) === '#FFFFFF' ? 'white' : 'dark').', whichever reads.'
                                : 'Left empty, the headings colour is used.'),
                    ]),

                Section::make('Your words')
                    ->description('English is the one every visitor can fall back to. Write it first.')
                    ->schema([
                        Tabs::make('Languages')
                            ->tabs(array_map(fn (string $locale): Tab => Tab::make(PropertyForm::LOCALES[$locale])->schema([
                                TextInput::make("tagline.{$locale}")
                                    ->label('Tagline')
                                    ->maxLength(140)
                                    ->extraInputAttributes(SetLocale::isRtl($locale) ? ['dir' => 'rtl'] : []),
                                Textarea::make("about.{$locale}")
                                    ->label('About us')
                                    ->rows(6)
                                    ->maxLength(4000)
                                    ->extraInputAttributes(SetLocale::isRtl($locale) ? ['dir' => 'rtl'] : []),
                            ]), SetLocale::SUPPORTED))
                            ->columnSpanFull(),
                    ]),

                Section::make('What the home page shows')
                    ->schema([
                        CheckboxList::make('sections')->options(CompanySite::SECTIONS)->columns(3),
                    ]),

                Section::make('How visitors reach you')
                    ->description('Your telephone, e-mail and address come from Company details.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('whatsapp')->label('WhatsApp number')->tel()->maxLength(40)
                            ->helperText('Left empty, your telephone number is used.'),
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
            Action::make('visit')
                ->label('Open the site')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): ?string => self::address($this->company()), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $this->company()->site_enabled && $this->record()->isPublished() && self::address($this->company()) !== null),
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-globe-alt')
                ->visible(fn (): bool => $this->record()->exists && ! $this->record()->isPublished())
                ->requiresConfirmation()
                ->modalDescription('Visitors can see your site as soon as Rihla has switched it on.')
                ->action(function (): void {
                    abort_unless(self::canAccess(), 403);
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
                    abort_unless(self::canAccess(), 403);
                    $this->record()->forceFill(['published_at' => null])->save();
                    Notification::make()->success()->title('Taken offline')->send();
                }),
        ];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = self::withoutEmptyLocales($this->form->getState(), ['tagline', 'about']);
        $data['font'] = array_key_exists((string) ($data['font'] ?? ''), HostPage::FONTS) ? $data['font'] : 'inter';
        $data['theme'] = array_key_exists((string) ($data['theme'] ?? ''), CompanySite::THEMES) ? $data['theme'] : CompanySite::CLASSIC;
        $data['sections'] = array_values(array_intersect((array) ($data['sections'] ?? []), array_keys(CompanySite::SECTIONS)));

        $site = $this->record();
        $site->fill($data);
        $site->company_id = (int) $this->company()->getKey();
        $site->save();

        Notification::make()->success()->title('Saved')->send();
    }

    private function company(): Partner
    {
        /** @var Partner */
        return Filament::getTenant();
    }

    private function record(): CompanySite
    {
        return CompanySite::firstOrNew(['company_id' => $this->company()->getKey()]);
    }
}
