<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Access;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** Nobody may remove the last Super Admin's role, themselves included (site audit). */
    protected function beforeSave(): void
    {
        /** @var User $user */
        $user = $this->record;
        $superAdmin = Role::query()->where('name', Access::SUPER_ADMIN)->value('id');

        if ($user->isTheLastSuperAdmin() && ! in_array((int) $superAdmin, array_map('intval', (array) ($this->data['roles'] ?? [])), true)) {
            Notification::make()->danger()
                ->title('That is the last Super Admin')
                ->body('Make somebody else a Super Admin first.')
                ->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->hidden(fn (User $record): bool => $record->is(auth()->user())),
        ];
    }
}
