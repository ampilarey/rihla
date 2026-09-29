<?php

namespace App\Filament\Resources\Partners\Tables;

use App\Models\Partner;
use App\Support\EncryptedFile;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The office's decisions about a host — §16.6.
 *
 * Each sets states that are deliberately not fillable
 * ({@see Partner}), with `forceFill`, and each is behind `partner.verify`:
 * whether somebody may take guests' money through Rihla is not an edit.
 * A reason is required wherever the host will read one.
 */
final class HostActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            self::scan(),
            self::verify(),
            self::refuse(),
            self::suspend(),
            self::reinstate(),
            self::recommend(),
        ])
            ->label('Host')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => auth()->user()?->can('partner.verify') === true);
    }

    private static function scan(): Action
    {
        return Action::make('registrationScan')
            ->label('Open the registration scan')
            ->icon('heroicon-o-document-magnifying-glass')
            ->visible(fn (Partner $record): bool => filled($record->registration_document_path))
            ->action(function (Partner $record): StreamedResponse {
                $path = (string) $record->registration_document_path;
                $contents = EncryptedFile::contents('documents', $path);

                abort_if($contents === null, 404);

                return response()->streamDownload(fn () => print ($contents), basename($path));
            });
    }

    private static function verify(): Action
    {
        return Action::make('verifyHost')
            ->label('Verify')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Partner $record): bool => $record->verification !== Partner::VERIFIED)
            ->requiresConfirmation()
            ->modalDescription('You have checked their Ministry of Tourism registration. Their approved listings can be found and booked once they are active.')
            ->action(function (Partner $record): void {
                $record->forceFill([
                    'verification' => Partner::VERIFIED,
                    'verified_at' => now(),
                    'verified_by' => auth()->id(),
                    'verification_note' => null,
                    'status' => $record->isSuspended() ? Partner::STATUS_SUSPENDED : Partner::STATUS_ACTIVE,
                ])->save();

                Notification::make()->success()->title($record->name.' is verified')->send();
            });
    }

    private static function refuse(): Action
    {
        return Action::make('refuseHost')
            ->label('Refuse')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Partner $record): bool => $record->verification !== Partner::REFUSED && ! $record->is_rihla)
            ->schema([
                Textarea::make('note')->label('What they need to send (they read this)')->required()->maxLength(1000),
            ])
            ->action(function (Partner $record, array $data): void {
                $record->forceFill([
                    'verification' => Partner::REFUSED,
                    'verified_at' => now(),
                    'verified_by' => auth()->id(),
                    'verification_note' => $data['note'],
                ])->save();
            });
    }

    private static function suspend(): Action
    {
        return Action::make('suspendHost')
            ->label('Suspend')
            ->icon('heroicon-o-pause-circle')
            ->color('danger')
            // Rihla's own rooms are not suspended from Rihla.
            ->visible(fn (Partner $record): bool => ! $record->isSuspended() && ! $record->is_rihla)
            ->schema([
                Textarea::make('reason')->label('Why (they read this)')->required()->maxLength(1000),
            ])
            ->modalDescription('Their listings leave search at once and their team is signed out of the host panel. Stays already booked are not touched.')
            ->action(function (Partner $record, array $data): void {
                $record->forceFill([
                    'status' => Partner::STATUS_SUSPENDED,
                    'suspended_reason' => $data['reason'],
                ])->save();
            });
    }

    private static function reinstate(): Action
    {
        return Action::make('reinstateHost')
            ->label('Reinstate')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (Partner $record): bool => $record->isSuspended())
            ->requiresConfirmation()
            ->action(function (Partner $record): void {
                $record->forceFill([
                    // Back to active only if somebody has verified them;
                    // otherwise back to waiting for that check.
                    'status' => $record->verification === Partner::VERIFIED ? Partner::STATUS_ACTIVE : Partner::STATUS_PENDING,
                    'suspended_reason' => null,
                ])->save();
            });
    }

    private static function recommend(): Action
    {
        return Action::make('recommendHost')
            ->label(fn (Partner $record): string => $record->recommended_at ? 'Stop recommending' : 'Recommend')
            ->icon('heroicon-o-star')
            ->schema(fn (Partner $record): array => $record->recommended_at ? [] : [
                Textarea::make('note')->label('Why Rihla recommends them (internal)')->maxLength(255),
            ])
            ->action(function (Partner $record, array $data): void {
                $record->forceFill($record->recommended_at
                    ? ['recommended_at' => null, 'recommended_note' => null]
                    : ['recommended_at' => now(), 'recommended_note' => $data['note'] ?? null])->save();
            });
    }
}
