<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Contact;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * Social links and the contact number — moved from the Blade admin, §9.2.
 *
 * The number here is the one every WhatsApp and phone link on the site
 * dials ({@see Contact}), so it is the most consequential
 * field in the admin and the one most worth keeping hard to get wrong.
 *
 * Reading needs `setting.view`; saving needs `setting.update`, as the
 * policy has always kept them. Somebody given only the first sees the form
 * read-only rather than a Save button that fails.
 *
 * @property-read Schema $form
 */
class SocialSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Social & contact';

    protected static ?string $title = 'Social links and contact number';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $slug = 'social-settings';

    // The Services page's view: a form and its actions, nothing else.
    protected string $view = 'filament.pages.services';

    /**
     * The placeholder once seeded, and removed from live data by a
     * migration. Refused here so it cannot be typed back in from the
     * example beside the box.
     */
    public const PLACEHOLDER_PLAYLIST = 'PLxxxxxxxxxx';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('setting.view') === true;
    }

    private static function canSave(): bool
    {
        return auth()->user()?->can('setting.update') === true;
    }

    public function mount(): void
    {
        $this->form->fill(Setting::getSocialSettings());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Social media')
                    ->description('Copy each from the address bar on the profile itself. A link left blank is left off the site.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('facebook_url')->label('Facebook')->url()->maxLength(255),
                        TextInput::make('instagram_url')->label('Instagram')->url()->maxLength(255),
                        TextInput::make('tiktok_url')->label('TikTok')->url()->maxLength(255),
                        TextInput::make('viber_url')->label('Viber')->url()->maxLength(255),
                    ]),

                Section::make('Contact')
                    ->columns(2)
                    ->schema([
                        TextInput::make('whatsapp_number')
                            ->label('WhatsApp number')
                            ->required()
                            ->maxLength(20)
                            // The old hint said "without + or country code"
                            // beside a placeholder that was the country code
                            // followed by the number. Anyone who followed the
                            // hint would have broken every WhatsApp link on
                            // the site.
                            ->helperText('Digits only, including the country code — for example 9607972434. Every WhatsApp and phone link on the site dials this.'),

                        TextInput::make('youtube_playlist_id')
                            ->label('YouTube playlist ID')
                            ->maxLength(50)
                            ->notIn([self::PLACEHOLDER_PLAYLIST])
                            ->validationMessages(['not_in' => 'That is the example, not a playlist. Copy the real ID from the playlist\'s address.'])
                            ->helperText('In a playlist address, the part after "list=".'),
                    ]),
            ])
            ->disabled(! self::canSave())
            ->statePath('data');
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return self::canSave()
            ? [Action::make('save')->label('Save')->submit('save')]
            : [];
    }

    public function save(): void
    {
        abort_unless(self::canSave(), 403);

        Setting::setSocialSettings($this->form->getState());

        Notification::make()->success()->title('Saved')->send();
    }
}
