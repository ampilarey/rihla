<?php

namespace App\Models;

use App\Support\Contact;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    /** Container key holding the social settings for the current request. */
    private const SOCIAL_CACHE = 'rihla.social_settings';

    /** Container key holding every row, keyed by `key`, for the current request. */
    private const ALL_CACHE = 'rihla.settings.all';

    protected static function booted(): void
    {
        // Any write to any setting drops both memos below. The table is a
        // handful of small JSON blobs, so re-reading all of it is cheaper
        // than working out which key changed.
        $forget = function () {
            app()->forgetInstance(self::SOCIAL_CACHE);
            app()->forgetInstance(self::ALL_CACHE);
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /**
     * Every row, keyed by `key`, read once per request.
     *
     * `getSocialSettings()` used to run its own query, and it was the only
     * caller — until the service registry (§15.3) needed a second key from
     * this same small table. A second `where('key', ...)->first()` is a
     * second query against `settings`, which is exactly the query budget
     * `ContactNumberTest` holds every page to one of. Every reader shares
     * this one query instead, however many keys the table grows to.
     *
     * @return Collection<string, self>
     */
    public static function allCached(): Collection
    {
        if (app()->bound(self::ALL_CACHE)) {
            return app()->make(self::ALL_CACHE);
        }

        $all = self::all()->keyBy('key');

        app()->instance(self::ALL_CACHE, $all);

        return $all;
    }

    /**
     * Read once per request, not once per call site.
     *
     * Every page calls this several times — the footer, the contact bar, the
     * floating button, and `Seo::organization()` all want the same row. Each
     * call issued its own query: the contact page ran nine identical selects
     * against `settings` to render one page, and nine was its entire query
     * budget.
     */
    public static function getSocialSettings()
    {
        if (app()->bound(self::SOCIAL_CACHE)) {
            return app()->make(self::SOCIAL_CACHE);
        }

        $value = self::readSocialSettings();

        app()->instance(self::SOCIAL_CACHE, $value);

        return $value;
    }

    /**
     * Every key the social settings are guaranteed to carry.
     *
     * The stored value is a free-form JSON blob, and the views read it with
     * direct array access — `$socialSettings['facebook_url']`, no fallback.
     * A row saved without one of these keys therefore took down every public
     * page with "Undefined array key". Merging over these defaults makes the
     * shape a promise of this method rather than a hope about the row.
     *
     * @return array<string, mixed>
     */
    public static function socialDefaults(): array
    {
        return [
            'facebook_url' => null,
            'instagram_url' => null,
            'tiktok_url' => null,
            'whatsapp_number' => Contact::FALLBACK,
            'viber_url' => null,
            'youtube_playlist_id' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function readSocialSettings(): array
    {
        $stored = self::allCached()->get('social')?->value;

        return array_merge(self::socialDefaults(), is_array($stored) ? $stored : []);
    }

    public static function setSocialSettings(array $data)
    {
        return self::updateOrCreate(
            ['key' => 'social'],
            ['value' => $data]
        );
    }

    public static function getWhatsAppNumber()
    {
        $settings = self::getSocialSettings();

        return $settings['whatsapp_number'] ?? Contact::FALLBACK;
    }

    public static function getYouTubePlaylistId()
    {
        $settings = self::getSocialSettings();

        return $settings['youtube_playlist_id'] ?? null;
    }
}
