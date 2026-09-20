<?php

namespace App\Filament\Resources\Documents\Tables;

use App\Models\Document;
use App\Services\Documents\DocumentWallet;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentsTable
{
    private const COLOURS = [
        Document::PENDING => 'warning',
        Document::VERIFIED => 'success',
        Document::REJECTED => 'danger',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('traveller.full_name')
                    ->label('Traveller')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->description(fn (Document $record): string => ucfirst($record->category)),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::COLOURS[$state] ?? 'gray')
                    ->sortable(),

                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->counts('versions')
                    ->alignCenter()
                    ->badge()
                    // Two or more means the document was replaced, which is
                    // exactly the case somebody chasing a visa needs to see.
                    ->color(fn (?int $state): string => ($state ?? 0) > 1 ? 'info' : 'gray'),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date('j M Y')
                    ->placeholder('—')
                    ->sortable()
                    ->color(fn (Document $record): string => $record->expiresWithinWindow() ? 'danger' : 'gray')
                    ->description(fn (Document $record): ?string => $record->expiresWithinWindow()
                        ? 'Inside the '.config('documents.passport_validity_months').'-month window'
                        : null),

                TextColumn::make('updated_at')->label('Updated')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(array_combine(Document::STATUSES, array_map('ucfirst', Document::STATUSES)))
                    ->multiple(),

                Filter::make('expiring')
                    ->label('Expiring soon')
                    ->query(fn (Builder $query): Builder => $query->expiringBefore(
                        now()->addMonths((int) config('documents.passport_validity_months', 6)),
                    )),
            ])
            ->recordActions([
                // A fresh signed URL each time the row is drawn, valid for
                // minutes. The link is the only thing between a passport scan
                // and whoever ends up holding the URL.
                Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Document $record): bool => auth()->user()?->can('document.download') === true
                        && $record->load('versions')->currentVersion() !== null)
                    ->url(fn (Document $record): string => app(DocumentWallet::class)
                        ->downloadUrl($record->load('versions')->currentVersion()), shouldOpenInNewTab: true),

                Action::make('verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Document $record): bool => $record->status !== Document::VERIFIED
                        && auth()->user()?->can('document.update') === true)
                    ->requiresConfirmation()
                    ->action(function (Document $record): void {
                        app(DocumentWallet::class)->verify($record);

                        Notification::make()->success()->title('Document verified')->send();
                    }),

                Action::make('reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Document $record): bool => $record->status !== Document::REJECTED
                        && auth()->user()?->can('document.update') === true)
                    ->schema([
                        Textarea::make('reason')->label('What is wrong with it')->required()->rows(2),
                    ])
                    ->action(function (Document $record, array $data): void {
                        app(DocumentWallet::class)->reject($record, $data['reason']);

                        Notification::make()->warning()->title('Document rejected')->send();
                    }),

                EditAction::make()->label('Open'),
            ])
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription('Passports, photographs and slips, kept per traveller. Every replacement is a new version — nothing is overwritten.');
    }
}
