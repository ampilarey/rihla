<?php

namespace App\Services;

use Carbon\Carbon;
use NumberFormatter;

class LocaleService
{
    /**
     * Get the current locale
     */
    public static function getCurrentLocale(): string
    {
        return app()->getLocale();
    }

    /**
     * Check if the current locale is RTL
     */
    public static function isRTL(): bool
    {
        return in_array(self::getCurrentLocale(), ['dv', 'ar', 'he', 'fa', 'ur']);
    }

    /**
     * Get the HTML direction attribute
     */
    public static function getHtmlDir(): string
    {
        return self::isRTL() ? 'rtl' : 'ltr';
    }

    /**
     * Get the HTML language attribute
     */
    public static function getHtmlLang(): string
    {
        $locale = self::getCurrentLocale();
        
        // Map locale codes to HTML lang attributes
        $langMap = [
            'en' => 'en',
            'dv' => 'dv-MV', // Dhivehi - Maldives
            'ar' => 'ar',
            'he' => 'he',
            'fa' => 'fa',
            'ur' => 'ur'
        ];
        
        return $langMap[$locale] ?? $locale;
    }

    /**
     * Format a date according to the current locale
     */
    public static function formatDate($date, ?string $format = null): string
    {
        if (!$date) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);
        $locale = self::getCurrentLocale();

        if ($format) {
            return $carbon->format($format);
        }

        // Locale-specific date formatting
        $formats = [
            'en' => 'M j, Y',
            'dv' => 'j M, Y',
            'ar' => 'j M, Y',
            'he' => 'j M, Y',
            'fa' => 'j M, Y',
            'ur' => 'j M, Y'
        ];

        $format = $formats[$locale] ?? 'M j, Y';
        return $carbon->format($format);
    }

    /**
     * Format a number according to the current locale
     */
    public static function formatNumber($number, int $decimals = 0): string
    {
        $locale = self::getCurrentLocale();
        
        // For Dhivehi and other RTL languages, use Arabic-Indic numerals
        if (self::isRTL()) {
            $formatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $decimals);
            return $formatter->format($number);
        }

        // For English and other LTR languages, use standard formatting
        return number_format($number, $decimals);
    }

    /**
     * Format currency according to the current locale
     */
    public static function formatCurrency($amount, string $currency = 'MVR'): string
    {
        $locale = self::getCurrentLocale();
        
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        
        return $formatter->formatCurrency($amount, $currency);
    }

    /**
     * Get available locales
     */
    public static function getAvailableLocales(): array
    {
        return [
            'en' => [
                'name' => 'English',
                'native' => 'English',
                'flag' => '🇺🇸',
                'rtl' => false
            ],
            'dv' => [
                'name' => 'Dhivehi',
                'native' => 'ދިވެހި',
                'flag' => '🇲🇻',
                'rtl' => false // Dhivehi is typically LTR but can be RTL
            ]
        ];
    }

    /**
     * Get locale info
     */
    public static function getLocaleInfo(string $locale): ?array
    {
        $locales = self::getAvailableLocales();
        return $locales[$locale] ?? null;
    }

    /**
     * Check if a locale is available
     */
    public static function isLocaleAvailable(string $locale): bool
    {
        return array_key_exists($locale, self::getAvailableLocales());
    }

    /**
     * Get the default locale
     */
    public static function getDefaultLocale(): string
    {
        return 'en';
    }

    /**
     * Set the application locale
     */
    public static function setLocale(string $locale): void
    {
        if (self::isLocaleAvailable($locale)) {
            app()->setLocale($locale);
            Carbon::setLocale($locale);
        }
    }
}
