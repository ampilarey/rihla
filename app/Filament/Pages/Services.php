<?php

namespace App\Filament\Pages;

use App\Support\Services as ServiceRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

/**
 * The on / coming-soon / off switch for each line of business — §15.3
 * (Phase 8.1) of the upgrade plan.
 *
 * This is the whole point of the registry: the owner asked for the ability
 * to turn a service on or off without a deploy, and this page is that
 * control. It gates {@see ServiceRegistry}, which nothing outside
 * this admin screen writes to.
 *
 * Umrah is not a row here. Nothing checks its state yet — no route is
 * gated on it — so a switch for it would move and do nothing, which is
 * worse than no switch: it would look like a working kill-switch for the
 * business right up until the day someone actually pulled it.
 *
 * @property-read Schema $form
 */
class Services extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Services';

    protected static ?string $title = 'Services';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected string $view = 'filament.pages.services';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('setting.update') === true;
    }

    public function mount(): void
    {
        $this->form->fill(ServiceRegistry::all());
    }

    public function form(Schema $schema): Schema
    {
        $options = [
            ServiceRegistry::ON => 'On — sold to the public',
            ServiceRegistry::COMING_SOON => 'Coming soon — enquiries only, no booking',
            ServiceRegistry::OFF => 'Off — not reachable',
        ];

        $fields = [];

        foreach (ServiceRegistry::catalogue() as $key => $meta) {
            $fields[] = Select::make($key)
                ->label($meta['label'])
                ->options($options)
                ->required()
                ->native(false);
        }

        return $schema
            ->components([
                Section::make()
                    ->description(
                        'Off is a 404 to a visitor. Coming soon keeps the page reachable '
                        .'but the page itself decides to offer an enquiry rather than a booking.',
                    )
                    ->schema($fields),
            ])
            ->statePath('data');
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Save')->submit('save'),
        ];
    }

    public function save(): void
    {
        ServiceRegistry::save($this->form->getState());

        Notification::make()->success()->title('Saved')->send();
    }
}
