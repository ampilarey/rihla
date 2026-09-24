<?php

namespace App\Casts;

use App\Support\EncryptedFile;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * A government identifier, encrypted in its column — §15.6 (Phase 11).
 *
 * Maldivian law requires a guest register, so a passport or ID number for
 * every guest has to be kept. This is the cast that keeps it.
 *
 * ## Why not Laravel's own `encrypted` cast
 *
 * Because `data:anonymise` and `data:forget` write their stand-ins through
 * the **query builder**, not through Eloquent — see
 * `Anonymise::standIn()`, which is handed straight to
 * `DB::table(...)->update()`. A plain `encrypted` cast would decrypt
 * perfectly until the day somebody scrubbed the test server, and every
 * read afterwards would throw `DecryptException` — on the one command
 * whose entire job is keeping real ID numbers off a public host.
 *
 * So this tolerates plaintext, exactly the way {@see EncryptedFile}
 * tolerates files written before encryption existed: **every payload says
 * which it is**. Three kinds of value read back correctly —
 *
 * 1. ciphertext this cast wrote,
 * 2. a scrubbed stand-in some command wrote straight to the column,
 * 3. a row that predates this cast.
 *
 * ## What it protects against, and what it does not
 *
 * The same honest boundary {@see EncryptedFile} draws: it
 * helps against a database dump taken without `.env`, which is most dumps
 * and the one that gets emailed about. It does nothing against anybody
 * holding both the dump and `APP_KEY`, which on cPanel is anybody with a
 * shell. A layer, not a safe.
 *
 * It also cannot be searched. That is accepted rather than worked around:
 * a register is produced, not queried, and a searchable hash beside it
 * would hand back the thing the encryption was for.
 */
class EncryptedIdentifier implements CastsAttributes
{
    /**
     * The marker that says a value is ciphertext.
     *
     * A passport number is alphanumeric and a Maldivian ID is `A` plus six
     * digits, so neither can begin with this and be mistaken for it.
     */
    public const MAGIC = 'enc:';

    /**
     * @param  Model  $model
     * @param  array<string, mixed>  $attributes
     */
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! str_starts_with($value, self::MAGIC)) {
            // Plaintext: written before this cast, or a scrubbed stand-in.
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::MAGIC)));
        } catch (DecryptException) {
            // A value marked as ciphertext that this key cannot open —
            // a restored database from another environment, or a rotated
            // APP_KEY. Null rather than throwing: a register that is
            // missing one number is a problem somebody can see and fix,
            // where a page that 500s on load tells them nothing.
            return null;
        }
    }

    /**
     * @param  Model  $model
     * @param  array<string, mixed>  $attributes
     */
    public function set($model, string $key, $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::MAGIC.Crypt::encryptString((string) $value);
    }
}
