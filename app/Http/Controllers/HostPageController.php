<?php

namespace App\Http\Controllers;

use App\Models\HostPage;
use App\Models\Partner;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Services\Stays\ShareCard;
use App\Support\Audience;
use App\Support\Services as ServiceRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * A host's own page — §16.8, §16 Phase 14.4.
 *
 * `/{locale}/stays/hosts/{slug}`: 404 unless the host is active and
 * verified and the page is published. A member previewing an unpublished
 * page arrives with a signed, expiring link — and that page tells crawlers
 * not to index it.
 */
class HostPageController extends Controller
{
    /**
     * Every host with a page a guest may open — §16 Phase 15.
     *
     * The same gate as {@see show()}: listed (active and verified) and
     * published, and at least one listing behind a door that is not off,
     * so the directory never links to a page with nothing on it to book.
     */
    public function index(): View
    {
        $types = StaysController::openTypes();
        abort_if($types === [], 404);

        $listed = fn ($properties) => $properties->listable()->ofType($types);

        $hosts = Partner::query()
            ->where('status', Partner::STATUS_ACTIVE)
            ->where('verification', Partner::VERIFIED)
            ->whereHas('page', fn ($page) => $page->whereNotNull('published_at'))
            ->whereHas('properties', $listed)
            ->with(['page', 'properties' => $listed])
            ->orderBy('name')
            ->get();

        return view('stays.hosts', [
            'hosts' => $hosts->map(fn (Partner $host): array => [
                'partner' => $host,
                'page' => $host->page,
                'listings' => $host->properties->count(),
                'places' => $host->properties
                    ->map(fn (Property $property): string => collect([$property->island, $property->atoll])->filter()->implode(', '))
                    ->filter()->unique()->sort()->values()->all(),
                'rating' => $host->rating(),
            ]),
        ]);
    }

    public function show(Request $request, Partner $partner): View|Response
    {
        $page = $this->page($partner);
        $previewing = $request->boolean('preview') && $request->hasValidSignature();

        abort_unless($page->isPublished() || $previewing, 404);

        $audience = Audience::fromLocale(app()->getLocale());

        $listings = $partner->properties()
            ->listable()
            ->with(['roomTypes', 'photos.roomType'])
            ->get();

        $view = view('stays.host', [
            'partner' => $partner,
            'page' => $page,
            'listings' => $listings,
            'audience' => $audience,
            'photos' => $this->photos($listings),
            'points' => $listings
                ->filter(fn (Property $property): bool => $property->latitude !== null && $property->longitude !== null)
                ->map(fn (Property $property): array => [
                    'lat' => (float) $property->latitude,
                    'lng' => (float) $property->longitude,
                    'name' => (string) $property->name,
                ])
                ->values()
                ->all(),
            'about' => $this->about($page),
            'faq' => $page->shows('faq') ? $page->faqFor(app()->getLocale()) : [],
            'shareCard' => $this->shareCardUrl($partner, $page),
            'previewing' => $previewing && ! $page->isPublished(),
            'rating' => $partner->rating(),
            'reviews' => $partner->reviews()->visible()->with('customer')->latest('submitted_at')->paginate(10, ['*'], 'reviews'),
        ]);

        if ($previewing) {
            return response($view)->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $view;
    }

    /** The 1200×630 preview a pasted link shows — the host's cover, as a listing's. */
    public function shareCard(Partner $partner, ShareCard $cards): Response|RedirectResponse
    {
        $page = $this->page($partner);
        abort_unless($page->isPublished(), 404);

        $png = $cards->bytesFor($page->cover_path, 'host-'.$partner->slug);

        if ($png === null) {
            return redirect()->away(asset('images/rihla-social.png'));
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /** A signed link to the page as it stands, published or not — for the host's own team. */
    public static function previewUrl(Partner $partner, string $locale = 'en'): string
    {
        return URL::temporarySignedRoute('stays.host', now()->addHour(), [
            'locale' => $locale,
            'partner' => $partner->slug,
            'preview' => 1,
        ]);
    }

    private function page(Partner $partner): HostPage
    {
        abort_unless($partner->isListed(), 404);

        // The Stays strand is off altogether: no host page either.
        abort_if(ServiceRegistry::isOff('stays_guesthouses') && ServiceRegistry::isOff('stays_rooms'), 404);

        $page = $partner->page;
        abort_if($page === null, 404);

        return $page;
    }

    /**
     * The story in the reader's language, or English and a flag saying so —
     * the same rule as the listing page.
     *
     * @return array{text: ?string, inEnglish: bool}
     */
    private function about(HostPage $page): array
    {
        $locale = app()->getLocale();
        $own = $page->getTranslation('about', $locale, false);

        if (filled($own)) {
            return ['text' => $own, 'inEnglish' => false];
        }

        $english = $page->getTranslation('about', 'en', false);

        return ['text' => filled($english) ? $english : null, 'inEnglish' => $locale !== 'en' && filled($english)];
    }

    /**
     * Photographs from the host's listings, a dozen at most.
     *
     * @param  Collection<int, Property>  $listings
     * @return list<array{src: string, alt: string}>
     */
    private function photos($listings): array
    {
        return $listings
            ->flatMap(fn (Property $property) => $property->photos->map(fn (PropertyPhoto $photo): array => [
                'src' => Storage::disk($photo->disk)->url($photo->path),
                'alt' => (string) ($photo->caption ?: ($photo->roomType->name ?? $property->name)),
            ]))
            ->take(12)
            ->values()
            ->all();
    }

    private function shareCardUrl(Partner $partner, HostPage $page): string
    {
        $cards = app(ShareCard::class);
        $version = $cards->isSupported() ? $cards->versionFor($page->cover_path) : null;

        return $version === null
            ? asset('images/rihla-social.png')
            : route('stays.host.card', ['partner' => $partner->slug]).'?v='.$version;
    }
}
