<?php

namespace App\Filament\Pages;

use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * Link this account to a Telegram chat so the staff bot acts as you —
 * batch A4 from Hijaz. The code is shown once and lasts ten minutes; the
 * chat is bound to this account, and one account to one chat.
 */
class Telegram extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Telegram';

    protected static ?string $title = 'Telegram';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.telegram';

    public ?string $code = null;

    public ?string $deepLink = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('telegram.link') === true;
    }

    public function getLink(): ?TelegramLink
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->telegramLink()->first();
    }

    public function isBotConfigured(): bool
    {
        return Bot::isConfigured();
    }

    public function getBotUsername(): ?string
    {
        return app(Bot::class)->username();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label(fn (): string => $this->getLink() ? 'Link a different chat' : 'Get a link code')
                ->icon('heroicon-o-link')
                ->visible(fn (): bool => $this->isBotConfigured())
                ->action(function (Linker $linker): void {
                    /** @var User $user */
                    $user = auth()->user();
                    $this->code = $linker->issueCode($user);
                    $this->deepLink = $linker->deepLink($this->code);
                }),
            Action::make('unlink')
                ->label('Unlink')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('This chat stops acting as you. You can link again any time.')
                ->visible(fn (): bool => $this->getLink() !== null)
                ->action(function (Linker $linker): void {
                    /** @var User $user */
                    $user = auth()->user();
                    $linker->revokeFor($user);
                    $this->code = null;
                    $this->deepLink = null;
                }),
        ];
    }
}
