<?php

namespace App\Filament;

use App\Support\Brand;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Staff initials, drawn here rather than fetched from ui-avatars.com.
 *
 * Filament's default provider builds a URL on a third-party service and puts
 * it in an <img>. Two problems, in order of seriousness: it sends every staff
 * member's name to a service Rihla has no agreement with, on every page load
 * of the panel; and the site's `img-src` allows `'self'`, `data:` and YouTube
 * thumbnails only, so the browser refuses it and the avatar renders broken.
 *
 * A data: URI is allowed by that policy, needs no request, and works with the
 * server offline.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = str(Filament::getNameForDefaultAvatar($record))
            ->trim()
            ->explode(' ')
            ->map(function (string $segment): string {
                $letters = preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment);

                return filled($letters) ? mb_strtoupper(mb_substr($letters, 0, 1)) : '';
            })
            ->filter()
            ->take(2)
            ->join('');

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
                <rect width="64" height="64" fill="{$this->background()}"/>
                <text x="32" y="32" fill="#FFFFFF" font-family="system-ui, sans-serif"
                      font-size="26" font-weight="600" text-anchor="middle"
                      dominant-baseline="central">{$this->escape($initials)}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function background(): string
    {
        return Brand::WINE;
    }

    private function escape(string $initials): string
    {
        return htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
