<?php

namespace App\Filament\Resources\Stays\Pages;

use App\Filament\Resources\Stays\StayResource;
use App\Models\Stay;
use App\Models\StayAccess;
use App\Services\Stays\StayGatekeeper;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewStay extends ViewRecord
{
    protected static string $resource = StayResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->stayLinkAction()];
    }

    /**
     * A link to the guest's own stay page — §16.7.
     *
     * The guest who booked online was shown one on the spot; this is for
     * the one who lost it, and for a stay taken by phone. The same rules
     * as the Pilgrim Portal's link: minted when the modal opens, shown
     * once, stored hashed — a system that can show a link again can be
     * made to show it to somebody else.
     */
    private function stayLinkAction(): Action
    {
        return Action::make('stayLink')
            ->label('Guest link')
            ->icon('heroicon-o-link')
            ->visible(fn (): bool => auth()->user()?->can('stay.update') === true)
            ->modalHeading('A link to this stay')
            ->modalDescription(fn (): string => sprintf(
                'Good for %d days. Send it on WhatsApp and it opens their stay: where it stands, what they owe, '
                .'how to send a transfer slip, and free cancellation while the window is open.%s',
                (int) config('portal.link_days', 30),
                $this->liveLinkCount() > 0
                    ? ' There '.($this->liveLinkCount() === 1 ? 'is 1 link' : 'are '.$this->liveLinkCount().' links')
                        .' already working for this stay.'
                    : '',
            ))
            ->schema([
                Textarea::make('link')
                    ->label('Copy this now — it is not shown again')
                    ->rows(3)
                    ->readOnly()
                    ->default(fn (): string => route('my-stay.enter', [
                        'locale' => app()->getLocale(),
                        'token' => app(StayGatekeeper::class)->issue($this->stay(), auth()->user()),
                    ]))
                    ->helperText('The token is stored hashed, so nobody — including us — can read it back afterwards.'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done')
            ->extraModalFooterActions([
                Action::make('revokeStayLinks')
                    ->label('Cancel every link for this stay')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->requiresConfirmation()
                    ->modalDescription('Every link issued for this stay stops working immediately. Use this when one went to the wrong number.')
                    ->action(function (): void {
                        $killed = app(StayGatekeeper::class)->revokeAllFor($this->stay());

                        Notification::make()
                            ->warning()
                            ->title(trans_choice('{1}:count link cancelled|[2,*]:count links cancelled', $killed, ['count' => $killed]))
                            ->send();
                    }),
            ]);
    }

    private function stay(): Stay
    {
        /** @var Stay */
        return $this->getRecord();
    }

    private function liveLinkCount(): int
    {
        return StayAccess::where('stay_id', $this->stay()->getKey())->live()->count();
    }
}
