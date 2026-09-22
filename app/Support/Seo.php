<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Models\Article;
use App\Models\GuideStep;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Structured data and per-locale link tags.
 *
 * Everything here is derived from what the site actually holds. Nothing is
 * invented to fill a schema field: a structured-data property that does not
 * match the visible page is a Google policy violation, and misrepresenting a
 * licensed travel operator's credentials is worse than a missing rich result.
 */
class Seo
{
    /** Maldives Ministry of Economic Development registration, shown in the header and footer. */
    public const REGISTRATION_NUMBER = 'C11452023';

    /**
     * The year the company was registered, which the footer counts from.
     *
     * A copyright line reading only the current year claims nothing about how
     * long the work has existed, and for a business whose registration number
     * ends in its founding year it reads as though the site appeared this
     * January. The footer renders a range from here to `date('Y')`, so the end
     * moves on its own and this end never does.
     *
     * A literal, deliberately, and the same one the registration number
     * carries. It records something that happened; it is not derived from
     * anything and must not start being.
     */
    public const FOUNDED = 2023;

    /**
     * The year span for the footer's copyright line.
     *
     * `now()` rather than `date()` so the boundary is reachable from a test:
     * PHP's own clock ignores Carbon's test time, which would have left the
     * collapse below asserted by nobody until the year it mattered.
     */
    public static function copyrightYears(): string
    {
        $current = now()->year;

        return $current > self::FOUNDED
            ? self::FOUNDED.'–'.$current
            : (string) self::FOUNDED;
    }

    public const CONTACT_EMAIL = 'info@rihlatravels.mv';

    /**
     * The number search engines are told to call.
     *
     * This was a hard-coded constant, so the structured data kept publishing
     * +9607972434 no matter what the WhatsApp number in Admin → Settings
     * said. Of the fourteen places the number was written by hand, this was
     * the one with the longest reach: Google caches it and shows it in the
     * knowledge panel, well beyond the site itself.
     */
    public static function contactPhone(): string
    {
        return '+'.Contact::whatsappNumber();
    }

    /**
     * The same page in each language, keyed by locale, for hreflang.
     *
     * Returns an empty array for URLs that are not locale-prefixed (admin,
     * auth, the JSON API). Those have no alternate to point at, and emitting
     * hreflang for them would claim a translation that does not exist.
     *
     * @return array<string, string>
     */
    public static function alternates(Request $request): array
    {
        $path = '/'.ltrim($request->path(), '/');
        $path = $path === '//' ? '/' : $path;

        $stripped = preg_replace('#^/(?:en|dv)(?=/|$)#', '', $path, 1, $count);

        if ($count === 0) {
            return [];
        }

        $alternates = [];

        foreach (SetLocale::SUPPORTED as $locale) {
            $alternates[$locale] = url('/'.$locale.$stripped);
        }

        return $alternates;
    }

    /**
     * The operator itself. Emitted on every public page.
     *
     * TravelAgency rather than the broader Organization: it is the accurate
     * type, and it is what lets the business appear in local travel results.
     *
     * @return array<string, mixed>
     */
    public static function organization(): array
    {
        $social = Setting::getSocialSettings();

        $sameAs = array_values(array_filter([
            $social['facebook_url'] ?? null,
            $social['instagram_url'] ?? null,
            $social['tiktok_url'] ?? null,
            $social['viber_url'] ?? null,
        ]));

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'TravelAgency',
            '@id' => url('/').'#organization',
            'name' => config('app.name'),
            'url' => url('/'),
            // 600 px wide, not the 6250 px original. Every scraper that
            // reads this — Google, WhatsApp, Facebook — was being handed a
            // 1.44 MB file to render a thumbnail.
            'logo' => asset('images/icon-512.png'),
            'identifier' => self::REGISTRATION_NUMBER,
            'telephone' => self::contactPhone(),
            'email' => self::CONTACT_EMAIL,
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Malé',
                'addressCountry' => 'MV',
            ],
            'areaServed' => ['@type' => 'Country', 'name' => 'Maldives'],
            'knowsLanguage' => ['en', 'dv'],
        ];

        if ($sameAs !== []) {
            $schema['sameAs'] = $sameAs;
        }

        return $schema;
    }

    /**
     * @param  array<int, array{name: string, url: string|null}>  $crumbs
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $crumbs): array
    {
        $items = [];

        foreach (array_values($crumbs) as $i => $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
            ];

            // The last crumb is the current page and carries no link, which is
            // what tells Google where the trail ends.
            if (! empty($crumb['url'])) {
                $item['item'] = $crumb['url'];
            }

            $items[] = $item;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * A single trip.
     *
     * TouristTrip, not Product: it is the accurate type and the one AI search
     * surfaces read for travel. Deliberately no AggregateRating — the site
     * holds no reviews, and inventing a rating to win stars in search results
     * is both a Google structured-data violation and a lie to customers.
     *
     * The price is modelled as an AggregateOffer with lowPrice rather than an
     * Offer with price, because `price_from_mvr` is a "from" figure. Emitting
     * it as an exact price would show a number in search that the customer
     * cannot actually book at.
     *
     * @return array<string, mixed>
     */
    public static function trip(Trip $trip, string $url): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'TouristTrip',
            'name' => $trip->title,
            'url' => $url,
            'startDate' => $trip->date_start->toDateString(),
            'endDate' => $trip->date_end->toDateString(),
            'provider' => ['@id' => url('/').'#organization'],
        ];

        if ($trip->summary) {
            $schema['description'] = $trip->summary;
        }

        if ($trip->cover_image) {
            $schema['image'] = url(Storage::url($trip->cover_image));
        }

        if ($trip->location) {
            $schema['itinerary'] = [
                '@type' => 'Place',
                'name' => $trip->location,
            ];
        }

        // A past departure cannot be booked, so it carries no offer at all
        // rather than an offer marked unavailable.
        if ($trip->price_from_mvr && $trip->status !== Trip::STATUS_PAST) {
            $schema['offers'] = [
                '@type' => 'AggregateOffer',
                'lowPrice' => (string) $trip->price_from_mvr,
                'priceCurrency' => 'MVR',
                'availability' => 'https://schema.org/InStock',
                'url' => $url,
            ];
        }

        return $schema;
    }

    /**
     * An article, as a BlogPosting.
     *
     * `author` is emitted only when one is recorded. Attributing an
     * unattributed article to the organisation would be a claim the page
     * does not make, and on religious guidance the author is not incidental
     * — a reader deciding whether to trust a fiqh explanation is partly
     * deciding whose it is.
     *
     * @return array<string, mixed>|null
     */
    public static function article(Article $article, string $url): ?array
    {
        $title = $article->getTranslation('title', app()->getLocale());

        if (blank($title) || ! $article->is_published) {
            return null;
        }

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => $title,
            'description' => $article->getTranslation('excerpt', app()->getLocale()) ?: null,
            'url' => $url,
            'datePublished' => $article->published_at?->toAtomString(),
            'dateModified' => $article->updated_at?->toAtomString(),
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
                'identifier' => self::REGISTRATION_NUMBER,
            ],
        ]);

        if ($article->cover_image) {
            $schema['image'] = url(Storage::url($article->cover_image));
        }

        if ($article->author) {
            $schema['author'] = [
                '@type' => 'Person',
                'name' => $article->author->name,
            ];
        }

        return $schema;
    }

    /**
     * A package, as a TouristTrip with an offer per departure.
     *
     * Everything below comes from what the page shows. No field is filled to
     * satisfy a schema: a structured-data property that does not match the
     * visible page is a Google policy violation, and on a licensed travel
     * operator's site an invented credential or price is worse than a missing
     * rich result.
     *
     * So: no aggregateRating, because there is no review system; no
     * itinerary Place unless a hotel names a city; and no offer at all for a
     * departure that has left, rather than an offer marked unavailable.
     *
     * @return array<string, mixed>|null
     */
    public static function package(Package $package, string $url): ?array
    {
        $title = $package->getTranslation('title', app()->getLocale());

        if (blank($title)) {
            return null;
        }

        $schema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'TouristTrip',
            'name' => $title,
            'description' => $package->getTranslation('summary', app()->getLocale()) ?: null,
            'url' => $url,
            'provider' => [
                '@type' => 'TravelAgency',
                'name' => config('app.name'),
                'identifier' => self::REGISTRATION_NUMBER,
            ],
        ]);

        if ($package->cover_image) {
            $schema['image'] = url(Storage::url($package->cover_image));
        }

        // One offer per upcoming departure, priced from its cheapest tier.
        // A departure with no price carries no offer: "from nothing" is not
        // a price, and omitting the field is honest where a zero is not.
        $offers = [];

        foreach ($package->publishedDepartures as $departure) {
            $lead = $departure->lead_price;

            if ($lead === null || $departure->date_start->isPast()) {
                continue;
            }

            $offers[] = array_filter([
                '@type' => 'Offer',
                // Whole units, as schema.org expects — the column is minor
                // units, and publishing 2850000 would advertise a hundredfold
                // price to every crawler that reads it.
                'price' => (string) $lead->major(),
                'priceCurrency' => $lead->currency,
                'availability' => $departure->is_sold_out
                    ? 'https://schema.org/SoldOut'
                    : 'https://schema.org/InStock',
                'validFrom' => $departure->date_start->toDateString(),
                'url' => $url,
            ]);
        }

        if ($offers !== []) {
            $schema['offers'] = $offers;
        }

        return $schema;
    }

    /**
     * The Umrah guide, as an ordered set of steps.
     *
     * HowTo rather than the FAQPage the plan named. The guide's entries are
     * ordered ritual steps — "Ihram", "Tawaf" — not questions with answers.
     * Wrapping them as Question/acceptedAnswer would describe content the page
     * does not contain. Google retired the HowTo rich result in 2023, so this
     * earns no carousel either way; it is here because AI search surfaces and
     * assistants read it, and because it is what the page actually is.
     *
     * @param  iterable<GuideStep>  $steps
     * @return array<string, mixed>|null
     */
    public static function guide(iterable $steps, string $url): ?array
    {
        $howToSteps = [];

        foreach ($steps as $step) {
            $howToSteps[] = array_filter([
                '@type' => 'HowToStep',
                'position' => $step->step_number,
                'name' => $step->title,
                'text' => $step->summary ?: $step->details,
                'url' => $url.'#step-'.$step->step_number,
            ], fn ($value) => ! in_array($value, [null, ''], true));
        }

        if ($howToSteps === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'HowTo',
            'name' => __('guide.How to Perform Umrah'),
            'url' => $url,
            'step' => $howToSteps,
        ];
    }

    /**
     * Render a schema array for embedding in a <script type="application/ld+json"> block.
     *
     * JSON_HEX_TAG is the load-bearing flag: it escapes < and > so that a trip
     * title containing "</script>" — all content here is author-supplied
     * through the admin panel — cannot close the block and have whatever
     * follows parsed as markup.
     *
     * JSON_UNESCAPED_UNICODE keeps Dhivehi readable rather than emitting a
     * wall of \uXXXX escapes; neither it nor JSON_UNESCAPED_SLASHES affects
     * safety, because JSON_HEX_TAG has already dealt with the angle brackets.
     */
    public static function json(array $schema): string
    {
        return json_encode(
            $schema,
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
