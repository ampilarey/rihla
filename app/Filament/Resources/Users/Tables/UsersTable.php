<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\Access;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('No role — cannot open the panel'),

                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->dateTime('j M Y')
                    ->placeholder('Not verified')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),

                // Deleting your own account signs you out of the panel you are
                // standing in, and there is no way back if you were the last
                // Super Admin.
                DeleteAction::make()->hidden(fn (User $record): bool => $record->is(auth()->user())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // The single delete hides itself for your own row and the
                    // model refuses the last Super Admin; the bulk one did
                    // neither (site audit).
                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action, Collection $records): void {
                            $superAdmins = User::role(Access::SUPER_ADMIN)->count();
                            $going = $records->filter(fn (User $user): bool => $user->hasRole(Access::SUPER_ADMIN))->count();

                            if ($records->contains(fn (User $user): bool => $user->is(auth()->user())) || $going >= $superAdmins) {
                                Notification::make()->danger()->title('Not these')->body('You cannot delete yourself or the last Super Admin.')->send();
                                $action->cancel();
                            }
                        }),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'));
    }
}
