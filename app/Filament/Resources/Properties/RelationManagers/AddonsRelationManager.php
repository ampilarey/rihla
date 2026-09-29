<?php

namespace App\Filament\Resources\Properties\RelationManagers;

use App\Models\Property;
use App\Models\PropertyAddon;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A listing's add-ons — §16.14, §16 Phase 15. Used by both panels.
 *
 * Prices are typed in whole units and stored in minor ones, through
 * {@see Money}, as a room's are. A blank price is "not offered to that
 * guest", which the helper text says, because a blank that meant "free"
 * would be the more natural misreading.
 *
 * Name and description are English on this screen; another language falls
 * back to it, the §15.4 rule for everything a listing says.
 */
class AddonsRelationManager extends RelationManager
{
    protected static string $relationship = 'addons';

    protected static ?string $title = 'Add-ons';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name.en')->label('Name')->required()->maxLength(120)
                ->helperText('"Airport transfer", "Breakfast", "Snorkelling trip".'),

            Textarea::make('description.en')->label('What it is')->rows(2)->maxLength(500),

            Select::make('pricing')
                ->options(PropertyAddon::PRICING)
                ->default(PropertyAddon::PER_STAY)
                ->required()
                ->helperText('Per person is charged once for every guest in the booking.'),

            TextInput::make('price_minor')
                ->label(fn (): string => 'Visitor price ('.$this->currency().')')
                ->numeric()
                ->minValue(0)
                ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : Money::ofMinor($state, $this->currency())->major())
                ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : Money::ofMajor((int) $state, $this->currency())->minor)
                ->helperText('Leave blank if visitors cannot add it.'),

            TextInput::make('local_price_minor')
                ->label(fn (): string => 'Local price ('.$this->localCurrency().')')
                ->numeric()
                ->minValue(0)
                ->formatStateUsing(fn (?int $state): ?int => $state === null ? null : Money::ofMinor($state, $this->localCurrency())->major())
                ->dehydrateStateUsing(fn ($state): ?int => blank($state) ? null : Money::ofMajor((int) $state, $this->localCurrency())->minor)
                ->helperText('Leave blank if Maldivian guests cannot add it.'),

            Toggle::make('is_active')->label('Offered')->default(true),

            TextInput::make('sort_order')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Add-on')->wrap(),
                TextColumn::make('pricing')->formatStateUsing(fn (?string $state): string => PropertyAddon::PRICING[$state] ?? '—'),
                TextColumn::make('price_minor')
                    ->label('Visitor')
                    ->placeholder('Not offered')
                    ->formatStateUsing(fn (?int $state): string => Money::ofMinor((int) $state, $this->currency())->format()),
                TextColumn::make('local_price_minor')
                    ->label('Local')
                    ->placeholder('Not offered')
                    ->formatStateUsing(fn (?int $state): string => Money::ofMinor((int) $state, $this->localCurrency())->format()),
                IconColumn::make('is_active')->label('Offered')->boolean(),
            ])
            ->headerActions([CreateAction::make()->label('Add an add-on')])
            ->recordActions([
                // Filled with every language, not the reader's one string —
                // otherwise saving an edit would drop the other translations.
                EditAction::make()->mutateRecordDataUsing(fn (array $data, PropertyAddon $record): array => [
                    ...$data,
                    'name' => $record->getTranslations('name'),
                    'description' => $record->getTranslations('description'),
                ]),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No add-ons yet')
            ->emptyStateDescription('Extras a guest can pick when they book. They go on the bill and are paid here, at the property.');
    }

    /** An edit page, not a view page — a relation manager on a view page is read-only (AGENTS.md). */
    public function isReadOnly(): bool
    {
        return false;
    }

    private function currency(): string
    {
        $property = $this->getOwnerRecord();

        return $property instanceof Property ? strtoupper((string) $property->currency) : 'USD';
    }

    private function localCurrency(): string
    {
        return strtoupper((string) config('marketplace.currencies.local', 'MVR'));
    }
}
