<?php

namespace App\Filament\Host\Pages;

use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\User;
use App\Support\EncryptedFile;
use App\Support\HostRole;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * A host signs up — §16.6.
 *
 * Makes the user, the host (`pending`, `verification = pending`), and the
 * owner membership in one transaction, then signs them in to a dashboard
 * that says their account is being checked. Nothing they list is shown to
 * a guest until a person at Rihla has verified their tourism registration.
 *
 * **Closed unless the owner has opened it** — see {@see isOpen()}. Not the
 * Breeze /register D16 closed; that stays closed.
 */
class RegisterHost extends Register
{
    /**
     * Open, and with terms to agree to. A checkbox agreeing to a document
     * that does not exist records nothing, so no terms means no sign-up.
     */
    public static function isOpen(): bool
    {
        return (bool) config('marketplace.host_registration.enabled')
            && filled(config('marketplace.host_terms.version'))
            && filled(config('marketplace.host_terms.url'));
    }

    public function mount(): void
    {
        if (! self::isOpen()) {
            throw new NotFoundHttpException;
        }

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        $requireRegistration = (bool) config('marketplace.require_registration', true);

        return $schema->components([
            TextInput::make('host_name')->label('Name of your guesthouse or business')->required()->maxLength(255),
            Select::make('kind')->label('What you run')->required()->options([
                Partner::KIND_GUESTHOUSE => 'A guesthouse',
                Partner::KIND_HOMESTAY => 'A homestay',
                Partner::KIND_RENTAL_OWNER => 'Rooms or homes to rent',
                Partner::KIND_AGENCY => 'An agency with several places',
            ]),
            TextInput::make('island')->label('Island')->required()->maxLength(120),
            TextInput::make('name')->label('Your name')->required()->maxLength(255)->autofocus(),
            TextInput::make('phone')->label('Phone')->tel()->required()->maxLength(40),
            TextInput::make('whatsapp')->label('WhatsApp (if different)')->tel()->maxLength(40),
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            TextInput::make('registration_number')
                ->label('Ministry of Tourism registration number')
                ->required($requireRegistration)
                ->maxLength(60),
            FileUpload::make('registration_document')
                ->label('A photo or scan of the registration')
                ->required($requireRegistration)
                ->disk('documents')
                ->directory('host-registrations')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])
                ->maxSize(8192)
                // Encrypted at rest, as every identity document is (§10.4).
                ->saveUploadedFileUsing(function (TemporaryUploadedFile $file): string {
                    $path = 'host-registrations/'.Str::uuid().'.'.$file->getClientOriginalExtension();
                    EncryptedFile::put('documents', $path, (string) file_get_contents($file->getRealPath()));

                    return $path;
                }),
            Checkbox::make('terms')
                ->label(new HtmlString('I agree to the <a href="'.e((string) config('marketplace.host_terms.url')).'" target="_blank" rel="noopener" class="underline">host terms</a>'))
                ->accepted(),
        ]);
    }

    /**
     * Filament sends a verification e-mail whether or not the panel has
     * the route it links to, and this one does not: there is no mail on
     * this host yet (§16.12), and a host is checked by a person at Rihla
     * rather than by clicking a link. Left alone, every sign-up would end
     * on a missing-route error after the account had been made.
     */
    protected function sendEmailVerificationNotification(Model $user): void
    {
        if (Filament::getCurrentOrDefaultPanel()->hasEmailVerification()) {
            parent::sendEmailVerificationNotification($user);
        }
    }

    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        // Per address, per hour — `marketplace.host_registration.per_hour`.
        // Filament's own limit is per minute; this is the one that stops a
        // bot filling Rihla's verification queue overnight.
        $key = 'host-register:'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, (int) config('marketplace.host_registration.per_hour', 5))) {
            throw new TooManyRequestsHttpException;
        }

        RateLimiter::hit($key, 3600);

        /** @var User $user */
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $host = Partner::create([
            'name' => $data['host_name'],
            'kind' => $data['kind'],
            'island' => $data['island'],
            'contact_name' => $data['name'],
            'phone' => $data['phone'],
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'],
            'registration_number' => $data['registration_number'] ?? null,
            'registration_document_path' => $data['registration_document'] ?? null,
            // A host sets their own prices; Rihla's margin is a commission.
            'pricing_model' => Partner::COMMISSION,
        ]);

        // Not fillable, on purpose: a host does not verify themselves.
        $host->forceFill([
            'verification' => Partner::VERIFICATION_PENDING,
            'status' => Partner::STATUS_PENDING,
            'terms_accepted_at' => now(),
            'terms_version' => (string) config('marketplace.host_terms.version'),
        ])->save();

        HostMembership::create([
            'partner_id' => $host->getKey(),
            'user_id' => $user->getKey(),
            'role' => HostRole::OWNER,
            'accepted_at' => now(),
        ]);

        return $user;
    }
}
