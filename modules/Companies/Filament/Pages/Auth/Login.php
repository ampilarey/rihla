<?php

namespace Modules\Companies\Filament\Pages\Auth;

use App\Support\PhoneNumber;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

/**
 * A company's sign-in (companies plan, Phase 3i, from Hijaz): an e-mail
 * address or a mobile number in one box, matched the way Rihla's own
 * /login matches it — a number by its key, so every way of writing it is
 * the same account.
 */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('E-mail or mobile number')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['inputmode' => 'email']);
    }

    /** @return array<string, mixed> */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) $data['email']);
        $key = str_contains($login, '@') ? null : PhoneNumber::key($login);

        return $key === null
            ? ['email' => $login, 'password' => $data['password']]
            : ['phone' => $key, 'password' => $data['password']];
    }
}
