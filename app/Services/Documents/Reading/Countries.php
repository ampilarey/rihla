<?php

namespace App\Services\Documents\Reading;

/**
 * A passport's machine-readable zone names countries by three letters
 * (MDV); the traveller's record keeps two (MV). Only the pairs that are
 * known here are translated — a code that is not is left out of the
 * proposal rather than guessed, because a wrong nationality on a visa
 * application is worse than a blank one.
 */
final class Countries
{
    /** @var array<string, string> */
    private const ISO3_TO_ISO2 = [
        'MDV' => 'MV', 'SAU' => 'SA', 'IND' => 'IN', 'LKA' => 'LK', 'PAK' => 'PK', 'BGD' => 'BD',
        'NPL' => 'NP', 'IDN' => 'ID', 'MYS' => 'MY', 'SGP' => 'SG', 'THA' => 'TH', 'PHL' => 'PH',
        'CHN' => 'CN', 'JPN' => 'JP', 'KOR' => 'KR', 'ARE' => 'AE', 'QAT' => 'QA', 'KWT' => 'KW',
        'OMN' => 'OM', 'BHR' => 'BH', 'EGY' => 'EG', 'TUR' => 'TR', 'IRN' => 'IR', 'IRQ' => 'IQ',
        'JOR' => 'JO', 'LBN' => 'LB', 'SYR' => 'SY', 'YEM' => 'YE', 'MAR' => 'MA', 'DZA' => 'DZ',
        'TUN' => 'TN', 'LBY' => 'LY', 'SDN' => 'SD', 'SOM' => 'SO', 'KEN' => 'KE', 'TZA' => 'TZ',
        'UGA' => 'UG', 'NGA' => 'NG', 'GHA' => 'GH', 'SEN' => 'SN', 'ZAF' => 'ZA', 'ETH' => 'ET',
        'GBR' => 'GB', 'IRL' => 'IE', 'FRA' => 'FR', 'DEU' => 'DE', 'ITA' => 'IT', 'ESP' => 'ES',
        'NLD' => 'NL', 'BEL' => 'BE', 'CHE' => 'CH', 'AUT' => 'AT', 'SWE' => 'SE', 'NOR' => 'NO',
        'DNK' => 'DK', 'FIN' => 'FI', 'POL' => 'PL', 'RUS' => 'RU', 'UKR' => 'UA', 'KAZ' => 'KZ',
        'UZB' => 'UZ', 'AFG' => 'AF', 'USA' => 'US', 'CAN' => 'CA', 'MEX' => 'MX', 'BRA' => 'BR',
        'AUS' => 'AU', 'NZL' => 'NZ', 'BRN' => 'BN', 'MMR' => 'MM', 'VNM' => 'VN', 'KHM' => 'KH',
        'BTN' => 'BT', 'MUS' => 'MU', 'SYC' => 'SC', 'ALB' => 'AL', 'BIH' => 'BA', 'XKX' => 'XK',
        'MKD' => 'MK', 'AZE' => 'AZ', 'TJK' => 'TJ', 'TKM' => 'TM', 'KGZ' => 'KG', 'PSE' => 'PS',
    ];

    public static function iso2(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        if (strlen($code) === 2 && ctype_alpha($code)) {
            return $code;
        }

        return self::ISO3_TO_ISO2[$code] ?? null;
    }
}
