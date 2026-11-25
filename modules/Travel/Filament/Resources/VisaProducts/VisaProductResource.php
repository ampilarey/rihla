<?php

namespace Modules\Travel\Filament\Resources\VisaProducts;

use App\Filament\Concerns\AuthorizesThroughCanMethods;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Travel\Filament\Resources\VisaProducts\Pages\ManageVisaProducts;
use Modules\Travel\Models\VisaProduct;
use UnitEnum;

/**
 * The visa list for other countries — docs/TRAVEL_AGENCY_PLAN.md (A3,
 * decision 2). Kept by staff, not typed in by a developer, so it does not
 * go stale. Never deleted: a visa that is no longer offered is switched off.
 */
class VisaProductResource extends Resource
{
    use AuthorizesThroughCanMethods;

    protected static ?string $model = VisaProduct::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Visa list';

    protected static ?string $modelLabel = 'visa';

    protected static UnitEnum|string|null $navigationGroup = 'Travel agency';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('travel.viewAny') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('travel.manage') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('travel.manage') === true;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * The fee is typed in rufiyaa and stored in laari.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fromForm(array $data): array
    {
        $data['fee_minor'] = filled($data['fee'] ?? null) ? (int) $data['fee'] * 100 : null;
        $data['documents'] = array_values(array_filter(array_map('trim', (array) ($data['documents'] ?? []))));
        unset($data['fee']);

        return $data;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('country')->required()->maxLength(80),
            TextInput::make('visa_type')->label('Visa type')->required()->maxLength(80)->placeholder('Tourist, 30 days'),
            TagsInput::make('documents')->label('Documents needed')->required()->columnSpanFull()
                ->placeholder('Type one and press Enter')
                ->helperText('The checklist every applicant works through, in order.'),
            TextInput::make('processing_days')->label('Usual working days')->integer()->minValue(1)->maxValue(365),
            TextInput::make('fee')->label('Fee')->prefix('MVR')->integer()->minValue(0)
                ->afterStateHydrated(fn (TextInput $component, ?VisaProduct $record) => $component->state($record?->fee_minor === null ? null : intdiv($record->fee_minor, 100))),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
            Toggle::make('is_active')->label('Offered')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('country')->searchable()->sortable(),
                TextColumn::make('visa_type')->label('Type'),
                TextColumn::make('documents')->label('Documents')->state(fn (VisaProduct $record): string => count($record->documents).' item(s)'),
                TextColumn::make('processing_days')->label('Days')->alignEnd(),
                TextColumn::make('fee_minor')->label('Fee')->alignEnd()->state(fn (VisaProduct $record): string => $record->fee()?->format() ?? 'On request'),
                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->defaultSort('country')
            ->recordActions([EditAction::make()->mutateDataUsing(fn (array $data): array => self::fromForm($data))])
            ->emptyStateHeading('No visas on the list yet')
            ->emptyStateDescription('Anything not on it is handled on request.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageVisaProducts::route('/')];
    }
}
