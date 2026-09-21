<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Identity documents and payment slips, encrypted on disk — §10.4.
 *
 * ## What this actually protects against, and what it does not
 *
 * Worth being exact, because "encrypted at rest" is a phrase people stop
 * reading after.
 *
 * **It helps against:** a backup of `storage/` taken without `.env` —
 * which is most backups, and the one that gets e-mailed about; a
 * directory-listing or traversal leak that exposes files but not the
 * application; and another account on the shared host reading files it
 * should not (ADR 0002).
 *
 * **It does not help against:** anybody who has both the files and
 * `APP_KEY`. On cPanel those sit in the same account, so somebody with a
 * shell has both. This is a layer, not a safe, and a passport scan is
 * still something to be careful with.
 *
 * ## Old files keep working
 *
 * Every encrypted payload starts with {@see MAGIC}. {@see contents()}
 * checks for it and hands back anything without it untouched, so files
 * written before this existed are still readable and nothing needs a
 * migration to happen before a download works. `documents:encrypt`
 * converts them at leisure.
 *
 * ## It costs streaming, and that is the trade
 *
 * A file has to be whole in memory to be decrypted, so a download is a
 * string rather than a stream. Uploads are capped at
 * `documents.max_kilobytes` (8 MB), and the ciphertext is roughly a third
 * larger again, so the worst case is about 20 MB of memory for one
 * download. That is affordable here and would not be without the cap.
 */
final class EncryptedFile
{
    /**
     * The marker that says a payload is ciphertext.
     *
     * Deliberately not valid at the start of a PDF, a JPEG, a PNG or a
     * HEIC — those begin `%PDF`, `\xFF\xD8`, `\x89PNG` and `ftyp` — so a
     * real document can never be mistaken for an encrypted one.
     */
    public const MAGIC = "RIHLAENC\x01";

    public static function enabled(): bool
    {
        return config('documents.encrypt_at_rest') === true;
    }

    /**
     * Write contents to a disk, encrypted when that is switched on.
     */
    public static function put(string $disk, string $path, string $contents): bool
    {
        return Storage::disk($disk)->put($path, self::wrap($contents));
    }

    /**
     * Read contents back, decrypting only what was encrypted.
     *
     * Null when the file is missing, so a caller distinguishes "gone" from
     * "empty". A payload that carries the marker but will not decrypt
     * throws rather than returning rubbish — a document silently served as
     * ciphertext is worse than an error somebody has to look at.
     */
    public static function contents(string $disk, string $path): ?string
    {
        $raw = Storage::disk($disk)->get($path);

        if ($raw === null) {
            return null;
        }

        return self::unwrap($raw);
    }

    /** Encrypt if switched on; hand back unchanged if not. */
    public static function wrap(string $contents): string
    {
        if (! self::enabled()) {
            return $contents;
        }

        // encryptString, not encrypt: `encrypt()` serialises first, which
        // on eight megabytes of binary is a pointless copy.
        return self::MAGIC.Crypt::encryptString(base64_encode($contents));
    }

    /**
     * Decrypt if it carries the marker; hand back unchanged if not.
     *
     * @throws DecryptException when a marked payload cannot be decrypted
     */
    public static function unwrap(string $raw): string
    {
        if (! self::looksEncrypted($raw)) {
            return $raw;
        }

        return (string) base64_decode(
            Crypt::decryptString(substr($raw, strlen(self::MAGIC))),
            true,
        );
    }

    public static function looksEncrypted(string $raw): bool
    {
        return str_starts_with($raw, self::MAGIC);
    }
}
