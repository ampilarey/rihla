<?php

namespace App\Filament\Host\Resources\Packages;

use App\Filament\Host\Resources\Packages\Pages\CreatePackage;
use App\Filament\Host\Resources\Packages\Pages\EditPackage;
use App\Filament\Host\Resources\Packages\Pages\ListPackages;
use App\Filament\Resources\Packages\Schemas\PackageForm;
use App\Models\Package;
use App\Models\Partner;
use App\Models\Property;
use App\Support\HostContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Packages a host builds — §16 Phase 16.
 *
 * An island holiday on one of the host's own listings: the words, the
 * nights, who it is sold to, a cover. The host hands it to Rihla, and
 * Rihla prices it (departures and price tiers, in `/staff`, where the seat
 * allocator lives) and publishes it. Once it is live the host can read it
 * but not change it — a page guests have booked from does not move under
 * them; the host asks Rihla, who can.
 *
 * Scoped to the tenant through `partner`, so a host sees only the packages
 * they wrote; Rihla's own packages on their guesthouse are not theirs.
 */
class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static ?string $slug = 'packages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Packages';

    protected static ?string $modelLabel = 'package';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canEdit(Model $record): bool
    {
        return parent::canEdit($record) && $record instanceof Package && ! $record->is_published;
    }

    public static function canDelete(Model $record): bool
    {
        return parent::canDelete($record) && $record instanceof Package && ! $record->is_published && $record->submitted_at === null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What guests read')->schema([PackageForm::contentTabs()]),

            Section::make('The holiday')->columns(2)->schema([
                Select::make('property_id')
                    ->label('At which of your places')
                    ->options(fn (): array => self::listingOptions())
                    ->required(),

                TextInput::make('nights')->numeric()->integer()->minValue(1)->maxValue(30)->required(),

                Toggle::make('flexible_dates')
                    ->label('Guests choose their own dates')
                    ->live()
                    ->helperText('Off means fixed dates — Rihla sets them with you.'),

                TextInput::make('min_nights')->label('Fewest nights')->numeric()->integer()->minValue(1)->maxValue(30)
                    ->visible(fn ($get): bool => (bool) $get('flexible_dates')),

                Select::make('sold_to')
                    ->label('Sold to')
                    ->options(['local' => 'Maldivians', 'tourist' => 'Visitors', Package::SOLD_TO_BOTH => 'Both'])
                    ->default('local')
                    ->required(),

                FileUpload::make('cover_image')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->directory('packages')->maxSize(8192),
            ]),
        ]);
    }

    /** @return array<int, string> */
    private static function listingOptions(): array
    {
        $host = HostContext::current();

        return $host instanceof Partner
            ? $host->properties()->get()->mapWithKeys(fn (Property $property): array => [$property->id => (string) $property->getTranslation('name', 'en')])->all()
            : [];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->wrap(),
                TextColumn::make('property.name')->label('Place'),
                TextColumn::make('nights'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Package $record): string => $record->hostStatus())
                    ->color(fn (string $state): string => match ($state) {
                        'Live' => 'success',
                        'Draft' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                self::submit(),
            ])
            ->emptyStateHeading('No packages yet')
            ->emptyStateDescription('Put together a holiday at your place — a weekend with the ferry, meals and a trip. Rihla prices it and puts it on the site.');
    }

    /** Hand the package to Rihla to price and publish. */
    public static function submit(): Action
    {
        return Action::make('submit')
            ->label('Send to Rihla')
            ->icon('heroicon-o-paper-airplane')
            ->visible(fn (Package $record): bool => $record->submitted_at === null && ! $record->is_published && self::canEdit($record))
            ->requiresConfirmation()
            ->modalDescription('Rihla sets the dates and prices with you and puts it on the site. You can still change the words until it goes live.')
            ->action(function (Package $record): void {
                $record->forceFill(['submitted_at' => now()])->save();

                Notification::make()->success()->title('Sent to Rihla')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPackages::route('/'),
            'create' => CreatePackage::route('/create'),
            'edit' => EditPackage::route('/{record}/edit'),
        ];
    }
}
