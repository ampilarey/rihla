<?php

namespace App\Models;

use App\Support\Contact;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    protected static function booted(): void
    {
        // Any write to any setting drops the memo below. The row is one
        // small JSON blob, so re-reading it is cheaper than working out
        // which key changed.
        $forget = fn () => app()->forgetInstance(self::SOCIAL_CACHE);

        static::saved($forget);
        static::deleted($forget);
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
        $stored = self::where('key', 'social')->first()?->value;

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
