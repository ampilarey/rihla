<?php

namespace App\Support;

/**
 * A staff avatar drawn here, rather than fetched from a third party.
 *
 * Both admin surfaces ship with this problem out of the box and neither is
 * obvious until a page is opened: Filament's default avatar provider builds a
 * URL on ui-avatars.com from the person's name, and Pulse builds one on
 * gravatar.com from a hash of their email address.
 *
 * Two problems, in order of seriousness. Every panel page load tells a service
 * Rihla has no agreement with who works here — by name in one case, and by a
 * hash of their email, which is a stable identifier across every site using
 * Gravatar, in the other. And the site's `img-src` allows `'self'`, `data:`
 * and YouTube's thumbnail hosts only, so the browser refuses the request and
 * the avatar renders as a broken image. The policy was doing its job; the
 * broken picture was the visible half of a privacy leak.
 *
 * A data: URI needs no request, is allowed by that policy, and renders with
 * the network unplugged.
 */
final class InitialsAvatar
{
    /** A data: URI holding up to two initials on the brand wine. */
    public static function forName(?string $name): string
    {
        $background = self::escape(Brand::WINE);
        $initials = self::escape(self::initials($name));

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
                <rect width="64" height="64" fill="{$background}"/>
                <text x="32" y="32" fill="#FFFFFF" font-family="system-ui, sans-serif"
                      font-size="26" font-weight="600" text-anchor="middle"
                      dominant-baseline="central">{$initials}</text>
            </svg>
            SVG;

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The first letter of each of the first two words.
     *
     * Leading punctuation is stripped before the letter is taken, so
     * "'Aishath Nasheed" gives AN rather than an apostrophe and an A, and a
     * word that is nothing but punctuation is dropped rather than
     * contributing a bracket. The word itself is kept either way: a name
     * written "(contractor) Ahmed Ali" gives CA, because the parenthetical
     * is still the first word. That is unchanged from the Filament provider
     * this was extracted from.
     */
    public static function initials(?string $name): string
    {
        return str((string) $name)
            ->trim()
            ->explode(' ')
            ->map(function (string $segment): string {
                $letters = preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment);

                return filled($letters) ? mb_strtoupper(mb_substr($letters, 0, 1)) : '';
            })
            ->filter()
            ->take(2)
            ->join('');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
