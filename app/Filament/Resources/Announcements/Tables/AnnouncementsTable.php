<?php

namespace App\Filament\Resources\Announcements\Tables;

use App\Models\Announcement;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('headline')
                    ->label('What it says')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Announcement $record): string => $record->departure->package->title ?? 'Departure'),

                TextColumn::make('departure.date_start')
                    ->label('Departure')
                    ->date('j M Y')
                    ->sortable(),

                TextColumn::make('published_at')
                    ->label('Status')
                    ->badge()
                    // `->state()`, not `->formatStateUsing()`: a null column
                    // state short-circuits formatting and renders an empty
                    // cell — and "not published" is exactly the null case
                    // this column exists to show.
                    ->state(fn (Announcement $record): string => match (true) {
                        $record->published_at === null => 'Draft',
                        $record->published_at->isFuture() => 'Goes out '.$record->published_at->format('j M, H:i'),
                        default => 'Sent '.$record->published_at->format('j M, H:i'),
                    })
                    ->color(fn (Announcement $record): string => match (true) {
                        $record->published_at === null => 'warning',
                        $record->published_at->isFuture() => 'info',
                        default => 'success',
                    }),

                TextColumn::make('writer.name')
                    ->label('Written by')
                    ->state(fn (Announcement $record): string => $record->writer->name ?? 'Unknown')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('drafts')
                    ->label('Not sent yet')
                    ->query(self::onlyDrafts(...)),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::publishAction(),
                    self::unpublishAction(),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing announced')
            ->emptyStateDescription('Announcements are read by the group and by the families they have shared a link with. They are about the whole departure, never about one person.');
    }

    /**
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    private static function onlyDrafts(Builder $query): Builder
    {
        return $query->whereNull('published_at');
    }

    /**
     * Putting it in front of the families.
     *
     * Its own permission, and its own confirmation. This is the step that
     * cannot be taken back: an announcement reaching forty households is
     * not undone by deleting the row afterwards.
     */
    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Send it to the families')
            ->icon('heroicon-o-paper-airplane')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Everybody on this departure and every family link they have given out will see this. That cannot be taken back.')
            ->visible(fn (Announcement $record): bool => $record->published_at === null
                && auth()->user()?->can('announcement.publish') === true)
            ->action(function (Announcement $record): void {
                $record->forceFill(['published_at' => now()])->save();

                Notification::make()->success()->title('Sent')->send();
            });
    }

    /**
     * Taking it off the page.
     *
     * Honest about what it does and does not do: it stops the page showing
     * it, and it does not unsee it. Said in the confirmation rather than
     * left for somebody to assume.
     */
    private static function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label('Take it down')
            ->icon('heroicon-o-eye-slash')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('It comes off the page. Anybody who already read it has already read it.')
            ->visible(fn (Announcement $record): bool => $record->published_at !== null
                && auth()->user()?->can('announcement.publish') === true)
            ->action(function (Announcement $record): void {
                $record->forceFill(['published_at' => null])->save();

                Notification::make()->warning()->title('Taken down')->send();
            });
    }
}
