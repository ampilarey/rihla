<?php

namespace App\Support;

use App\Models\CompanySite;

/**
 * Rihla's brand colours, swapped for a company's on its own site —
 * companies plan, Phase 4b.
 *
 * The public pages a company's site serves (packages, the enquiry form,
 * checkout and the portal from 4c) were written in Rihla's palette: `wine`
 * for buttons, links and selected states, `gold` for accents. Rewriting
 * every view for a second palette would fork them; instead the company's
 * frame emits one stylesheet that gives each of those classes the
 * company's colour. It is unlayered and comes after the built CSS, so it
 * wins over the utilities without `!important`.
 *
 * Two colours, each already checked when the company saved it:
 * - **primary** is readable on a white page (4.5:1), so every class that
 *   colours *text* takes it, darkened, never lightened.
 * - **accent** has white or dark words that read on it, so every class that
 *   fills a *surface* takes it, and white words written on such a surface
 *   become whichever of the two reads ({@see CompanySite::textOnAccent()}).
 */
final class SitePalette
{
    /** The shades Tailwind defines for both palettes, and the bare name. */
    private const SHADES = ['', '50', '100', '200', '300', '400', '500', '600', '700', '800', '900'];

    /** The states a class is written in across the public views. */
    private const VARIANTS = ['', 'hover', 'focus', 'focus-visible', 'active', 'group-hover'];

    public function __construct(private readonly CompanySite $site) {}

    public function css(): string
    {
        $rules = [];
        $accent = $this->site->accent();
        $primary = $this->site->primary();
        $onAccent = $this->site->textOnAccent();

        foreach (['wine', 'gold'] as $palette) {
            foreach (self::SHADES as $shade) {
                $surface = $this->surface($accent, $shade);
                $text = $this->text($primary, $shade);
                $name = $palette.($shade === '' ? '' : '-'.$shade);

                $rules[$this->selectors('bg-'.$name)] = 'background-color:'.$surface;
                $rules[$this->selectors('border-'.$name)] = 'border-color:'.$surface;
                $rules[$this->selectors('border-s-'.$name)] = 'border-inline-start-color:'.$surface;
                $rules[$this->selectors('ring-'.$name)] = '--tw-ring-color:'.$surface;
                $rules[$this->selectors('text-'.$name)] = 'color:'.$text;

                // White words written on a filled shade become whichever
                // of white and dark reads on the company's colour.
                if ($this->isFill($shade)) {
                    $rules['.bg-'.$this->escape($name).'.text-white'] = 'color:'.$onAccent;
                }
            }
        }

        // Rihla's component classes carry the palette through @apply, so
        // the utility rules above cannot reach them.
        $tint = $this->mix($accent, '#FFFFFF', 0.9);
        $rules['.btn-primary,.btn-sky,.btn-action,.btn-gold,.badge-wine,.badge-gold,.section-wine'] = "background-color:{$accent};border-color:{$accent};color:{$onAccent}";
        $rules['.btn-primary:hover,.btn-sky:hover,.btn-action:hover,.btn-gold:hover'] = 'background-color:'.$this->mix($accent, '#000000', 0.12);
        $rules['.btn-primary:focus,.btn-action:focus,.btn-gold:focus,.btn-secondary:focus'] = '--tw-ring-color:'.$accent;
        $rules['.btn-secondary'] = "background-color:#FFFFFF;border-color:{$accent};color:{$primary}";
        $rules['.btn-secondary:hover'] = 'background-color:'.$tint;
        $rules['.section-gradient'] = "background-image:none;background-color:{$accent};color:{$onAccent}";
        $rules['.section-title::after'] = 'background-color:'.$accent;
        $rules['.divider-gold,.divider-wine'] = 'border-color:'.$this->mix($accent, '#FFFFFF', 0.6);

        $css = '';
        foreach ($rules as $selector => $declaration) {
            $css .= $selector.'{'.$declaration.'}';
        }

        return $css;
    }

    /** A filled surface: tints of the accent for the light shades, the accent and darker for the rest. */
    private function surface(string $accent, string $shade): string
    {
        return match ($shade) {
            '50' => $this->mix($accent, '#FFFFFF', 0.94),
            '100' => $this->mix($accent, '#FFFFFF', 0.88),
            '200' => $this->mix($accent, '#FFFFFF', 0.75),
            '300' => $this->mix($accent, '#FFFFFF', 0.55),
            '400' => $this->mix($accent, '#FFFFFF', 0.3),
            '', '500' => $accent,
            '600' => $this->mix($accent, '#000000', 0.12),
            '700' => $this->mix($accent, '#000000', 0.24),
            '800' => $this->mix($accent, '#000000', 0.36),
            default => $this->mix($accent, '#000000', 0.48),
        };
    }

    /**
     * Words: the primary, never lighter — lightening a colour checked at
     * 4.5:1 is how it stops being readable. The light shades only ever sit
     * on a dark surface, where a light tint of the accent is what reads.
     */
    private function text(string $primary, string $shade): string
    {
        return match ($shade) {
            '50', '100', '200' => '#FFFFFF',
            '300', '400' => $this->mix($primary, '#FFFFFF', 0.8),
            '', '500', '600' => $primary,
            '700' => $this->mix($primary, '#000000', 0.15),
            default => $this->mix($primary, '#000000', 0.3),
        };
    }

    private function isFill(string $shade): bool
    {
        return in_array($shade, ['', '500', '600', '700', '800', '900'], true);
    }

    /** `.bg-wine-600, .hover\:bg-wine-600:hover, …` for one class. */
    private function selectors(string $class): string
    {
        return implode(',', array_map(function (string $variant) use ($class): string {
            $escaped = $this->escape($class);

            return match ($variant) {
                '' => '.'.$escaped,
                'group-hover' => '.group:hover .group-hover\\:'.$escaped,
                default => '.'.$variant.'\\:'.$escaped.':'.$variant,
            };
        }, self::VARIANTS));
    }

    private function escape(string $class): string
    {
        return str_replace(['/', '.'], ['\\/', '\\.'], $class);
    }

    /** `$a` moved `$amount` of the way towards `$b`, as #RRGGBB. */
    public function mix(string $a, string $b, float $amount): string
    {
        [$ar, $ag, $ab] = $this->rgb($a);
        [$br, $bg, $bb] = $this->rgb($b);

        $channel = fn (int $x, int $y): int => (int) round($x + ($y - $x) * $amount);

        return sprintf('#%02X%02X%02X', $channel($ar, $br), $channel($ag, $bg), $channel($ab, $bb));
    }

    /** @return array{int, int, int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
