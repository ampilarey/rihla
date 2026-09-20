<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Facts about a person that outlive every lead they will ever be — §8.1.
 *
 * On the customer rather than the enquiry, because a tag on an enquiry is
 * lost the moment the enquiry closes, which is exactly when it starts being
 * useful. Folded to lower case on the model, so "Ramadan" and "ramadan"
 * typed by two people do not become two facts.
 */
class TagsRelationManager extends RelationManager
{
    protected static string $relationship = 'tags';

    /**
     * A relation manager on a `ViewRecord` page is **read-only by
     * default**, and silently so: Filament's own `isReadOnly()` returns
     * true for any relation manager whose page is a view page, and the
     * Create and Delete actions then simply do not render — no error, no
     * warning, and a test asserting the page loads still passes.
     *
     * The customer page is a view page on purpose, because almost nothing
     * on it is editable and a screen full of disabled fields reads as
     * broken. Tags are the exception, so this says so. Permission is still
     * checked in {@see canCreate()} and {@see canDelete()}.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    protected static ?string $title = 'Tags';

    protected static ?string $modelLabel = 'tag';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('tag')
                ->required()
                ->maxLength(40)
                ->helperText('Short and reusable: "prefers ramadan", "wheelchair", "travels with her mother". Case does not matter.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tag')->label('Tag'),
                TextColumn::make('author.name')->label('Added by')->state(
                    fn ($record): string => $record->author->name ?? '—',
                ),
                TextColumn::make('created_at')->label('Added')->date('j M Y'),
            ])
            ->defaultSort('tag')
            ->headerActions([CreateAction::make()->label('Add a tag')])
            ->recordActions([DeleteAction::make()])
            ->emptyStateHeading('No tags yet')
            ->emptyStateDescription('Things worth knowing before you ring them, that no booking record holds.');
    }

    /**
     * Gated on `customer.tag` directly, not on a CustomerTag policy.
     *
     * A tag has no life of its own — it is a fact about the customer — so
     * a five-verb policy for it would be five permissions nobody would
     * ever grant separately. Filament asks the model policy by default and
     * denies without one, which is why these three are explicit.
     */
    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('customer.tag') === true;
    }

    protected function canCreate(): bool
    {
        return auth()->user()?->can('customer.tag') === true;
    }

    protected function canDelete(Model $record): bool
    {
        return auth()->user()?->can('customer.tag') === true;
    }
}
