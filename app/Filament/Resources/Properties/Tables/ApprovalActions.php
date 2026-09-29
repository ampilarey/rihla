<?php

namespace App\Filament\Resources\Properties\Tables;

use App\Models\Property;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;

/**
 * Rihla's check of a host's listing — §16.6.
 *
 * Behind `partner.verify`: whether a listing may be shown to guests is the
 * same kind of decision as whether its host may sell, and it is taken by
 * the same people. Approval is not fillable ({@see Property}); these are
 * the only places it moves on the staff side.
 */
final class ApprovalActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('approveListing')
                ->label('Approve')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (Property $record): bool => $record->approval !== Property::APPROVED)
                ->requiresConfirmation()
                ->modalDescription('Guests can find and book it once it is published and its host is verified and active.')
                ->action(fn (Property $record) => $record->forceFill([
                    'approval' => Property::APPROVED,
                    'approved_at' => now(),
                    'approved_by' => auth()->id(),
                    'approval_note' => null,
                ])->save()),

            Action::make('requestChanges')
                ->label('Request changes')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn (Property $record): bool => $record->partner?->is_rihla !== true)
                ->schema([
                    Textarea::make('note')->label('What to change (the host reads this)')->required()->maxLength(1000),
                ])
                ->action(fn (Property $record, array $data) => $record->forceFill([
                    'approval' => Property::CHANGES_REQUESTED,
                    'approval_note' => $data['note'],
                ])->save()),

            Action::make('withdrawListing')
                ->label('Withdraw')
                ->icon('heroicon-o-eye-slash')
                ->color('danger')
                ->visible(fn (Property $record): bool => $record->approval === Property::APPROVED)
                ->schema([
                    Textarea::make('note')->label('Why (the host reads this)')->required()->maxLength(1000),
                ])
                ->modalDescription('It leaves search and its page at once. Stays already booked are not touched.')
                ->action(fn (Property $record, array $data) => $record->forceFill([
                    'approval' => Property::WITHDRAWN,
                    'approval_note' => $data['note'],
                ])->save()),
        ])
            ->label('Approval')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => auth()->user()?->can('partner.verify') === true);
    }
}
