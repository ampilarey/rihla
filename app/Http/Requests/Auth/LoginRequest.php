<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // An e-mail address or a mobile number (companies plan, Phase 3i):
            // one box, still called `email` so nothing that reads it moves.
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->credentials(), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * By mobile number when the box holds no "@" and reads as one, by e-mail
     * otherwise. A number is matched by its key, so every way of writing it
     * opens the same account; a wrong one fails as a wrong e-mail does.
     *
     * @return array<string, string>
     */
    private function credentials(): array
    {
        $login = trim((string) $this->string('email'));
        $key = str_contains($login, '@') ? null : PhoneNumber::key($login);

        return $key === null
            ? ['email' => $login, 'password' => (string) $this->string('password')]
            : ['phone' => $key, 'password' => (string) $this->string('password')];
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        // A number counts by its key, so writing it three ways is three tries
        // at one account, not one try each at three.
        $login = trim((string) $this->string('email'));
        $key = str_contains($login, '@') ? null : PhoneNumber::key($login);

        return Str::transliterate(Str::lower($key ?? $login).'|'.$this->ip());
    }
}
