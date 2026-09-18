<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditObserver
{
    /**
     * Attribute names never written to the log, whatever model they appear on.
     *
     * A trail that records a password hash, a session token or a reset token
     * turns the audit table into the most valuable table in the database —
     * and it is the one most people are allowed to read.
     *
     * @var list<string>
     */
    private const NEVER_RECORD = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
    ];

    /**
     * Timestamps change on every write and say nothing the log does not
     * already say more precisely.
     *
     * @var list<string>
     */
    private const NOISE = ['created_at', 'updated_at'];

    public function created(Model $model): void
    {
        $this->record($model, AuditLog::CREATED, null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changed = $this->clean($model->getChanges());

        // A save that altered nothing of substance — only a timestamp, or a
        // redacted field — is not an event worth a row.
        if ($changed === []) {
            return;
        }

        $before = [];

        foreach (array_keys($changed) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->record($model, AuditLog::UPDATED, $before, $changed);
    }

    public function deleted(Model $model): void
    {
        // The whole record, because after this there is nothing left to
        // compare against. This is the event an audit trail exists for.
        $this->record($model, AuditLog::DELETED, $this->clean($model->getAttributes()), null);
    }

    private function record(Model $model, string $event, ?array $old, ?array $new): void
    {
        $user = Auth::user();
        $request = request();

        // When the record being deleted *is* the acting user — the "delete my
        // account" path — the row this key points at is already gone by the
        // time `deleted` fires, and the foreign key would reject the insert.
        // The snapshot below still names them, which is what the log is for.
        $actorIsBeingDeleted = $event === AuditLog::DELETED
            && $user !== null
            && $model instanceof User
            && $model->getKey() === $user->getKey();

        AuditLog::create([
            'user_id' => $actorIsBeingDeleted ? null : $user?->getKey(),
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            // Absent in console context, which is itself worth being able to
            // tell apart from a change made through the panel.
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'url' => $request?->fullUrl(),
        ]);
    }

    /** @return array<string, mixed> */
    private function clean(array $attributes): array
    {
        return array_diff_key(
            $attributes,
            array_flip(array_merge(self::NEVER_RECORD, self::NOISE)),
        );
    }
}
