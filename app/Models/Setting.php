<?php

namespace App\Models;

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

    public static function getSocialSettings()
    {
        $settings = self::where('key', 'social')->first();

        if (! $settings) {
            return [
                'facebook_url' => null,
                'instagram_url' => null,
                'tiktok_url' => null,
                'whatsapp_number' => '9607972434',
                'viber_url' => null,
                'youtube_playlist_id' => null,
            ];
        }

        return $settings->value;
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

        return $settings['whatsapp_number'] ?? '9607972434';
    }

    public static function getYouTubePlaylistId()
    {
        $settings = self::getSocialSettings();

        return $settings['youtube_playlist_id'] ?? null;
    }
}
