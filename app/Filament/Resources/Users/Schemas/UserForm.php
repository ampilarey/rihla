<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Support\Access;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->label('Email address')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            // Required when creating, left alone when editing. Filament
            // dehydrates a blank password field away rather than writing an
            // empty hash over a working one.
            TextInput::make('password')
                ->password()
                ->revealable()
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->minLength(8)
                ->helperText(fn (string $operation): string => $operation === 'create'
                    ? 'At least 8 characters.'
                    : 'Leave blank to keep the current password.'),

            // Options come from the relationship, so they are keyed by role
            // id. Keying the descriptions by name instead silently produced
            // no descriptions at all — and writing role names into
            // `model_has_roles.role_id`, which the foreign key refused.
            CheckboxList::make('roles')
                ->relationship(titleAttribute: 'name')
                ->descriptions(fn (): array => Role::query()
                    ->pluck('name', 'id')
                    ->map(fn (string $name): string => self::descriptions()[$name] ?? '')
                    ->all())
                ->columns(2)
                ->helperText('A role with no permissions yet still grants access to the panel, and nothing else.'),
        ]);
    }

    /**
     * What each role can actually do today, rather than what its name implies.
     *
     * Four of the nine hold nothing but panel access, because the work they
     * describe — bookings, payments, visas, pilgrim support — does not exist
     * in the software yet. Saying so here is the difference between a role
     * that is scoped and a role that looks enforced and is not.
     *
     * @return array<string, string>
     */
    private static function descriptions(): array
    {
        $nothingYet = 'Panel access only — this work is not in the software yet.';

        return [
            Access::SUPER_ADMIN => 'Everything, including staff accounts.',
            Access::OPERATIONS_MANAGER => 'All content, plus site settings and the audit log.',
            Access::CONTENT_MANAGER => 'All content. Not settings.',
            Access::REPORTING => 'Read-only across content.',
            Access::BOOKING_STAFF => $nothingYet,
            Access::FINANCE => $nothingYet,
            Access::VISA_STAFF => $nothingYet,
            Access::PILGRIM_SUPPORT => $nothingYet,
            Access::TOUR_LEADER => $nothingYet,
        ];
    }
}
