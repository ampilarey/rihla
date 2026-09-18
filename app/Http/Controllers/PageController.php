<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\GuideStep;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    public function social()
    {
        $socialSettings = Setting::getSocialSettings();

        return view('pages.social', compact('socialSettings'));
    }

    public function contact()
    {
        $socialSettings = Setting::getSocialSettings();

        return view('pages.contact', compact('socialSettings'));
    }

    public function guide()
    {
        $locale = app()->getLocale();
        $guideSteps = GuideStep::published()
            ->forLocale($locale)
            ->ordered()
            ->get();

        return view('pages.guide', compact('guideSteps'));
    }

    /**
     * Send the bare domain to a localised URL.
     *
     * Not a permanent redirect: the target depends on the visitor's session and
     * Accept-Language header, so caching it would lock them to one language.
     */
    public function root(): RedirectResponse
    {
        // SetLocale has already resolved this from the session, or on a first
        // visit from Accept-Language.
        return redirect('/'.app()->getLocale());
    }

    /**
     * Switch language and move the visitor to the same page in it.
     *
     * Now that public URLs carry their locale in the path, updating the session
     * alone is not enough — it would leave the visitor sitting on /en/guide
     * reading Dhivehi, and a refresh would put the page back into English.
     */
    public function setLocale(Request $request, string $code): RedirectResponse
    {
        if (! in_array($code, SetLocale::SUPPORTED, true)) {
            return back();
        }

        session(['app_locale' => $code]);

        $referer = (string) $request->headers->get('referer');
        $path = '/'.ltrim((string) (parse_url($referer, PHP_URL_PATH) ?: '/'), '/');

        $stripped = preg_replace('#^/(?:en|dv)(?=/|$)#', '', $path, 1, $count);

        // Admin and auth pages are not localised in the path. The session is
        // updated and the visitor stays where they are.
        if ($count === 0) {
            return back();
        }

        $query = (string) (parse_url($referer, PHP_URL_QUERY) ?: '');

        return redirect('/'.$code.$stripped.($query !== '' ? '?'.$query : ''));
    }

    public function guidePdf()
    {
        $locale = app()->getLocale();
        $guideSteps = GuideStep::published()
            ->forLocale($locale)
            ->ordered()
            ->get();

        $pdf = Pdf::loadView('pdf.guide', compact('guideSteps', 'locale'));

        $filename = 'umrah-guide-'.$locale.'-'.now()->format('Y-m-d').'.pdf';

        return $pdf->download($filename);
    }

    public function guideStepsApi()
    {
        $locale = request()->get('locale', app()->getLocale());

        if (! in_array($locale, ['en', 'dv'], true)) {
            // `request()->json()` reads the *request* body; it never produces a
            // response. The guard therefore fell through and an unknown locale
            // was answered with 200 and an empty step list.
            return response()->json(['error' => 'Invalid locale'], 400);
        }

        $guideSteps = GuideStep::published()
            ->forLocale($locale)
            ->ordered()
            ->get()
            ->map(function ($step) {
                return [
                    'step_number' => $step->step_number,
                    'title' => $step->title,
                    'summary' => $step->summary,
                    'details' => $step->details,
                    'dua_text' => $step->dua_text,
                    'fiqh_notes' => $step->fiqh_notes,
                    'checklist' => $step->checklist,
                    'image_url' => $step->image_url,
                    'video_url' => $step->video_url,
                ];
            });

        return response()->json([
            'locale' => $locale,
            'total_steps' => $guideSteps->count(),
            'steps' => $guideSteps,
        ]);
    }
}
