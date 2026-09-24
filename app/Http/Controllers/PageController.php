<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\GuideStep;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        return view('pages.contact', [
            'socialSettings' => $socialSettings,
            // For the enquiry form's "which package" field. Published only:
            // a visitor should not be able to ask about something that is
            // not on sale.
            'packages' => EnquiryController::packageOptions(),
        ]);
    }

    /**
     * The published guide steps, read in a given locale.
     *
     * The Dhivehi steps shipped as machine-generated filler: all ten carried
     * the *same* du'a and the same reference_text, where the English ten carry
     * ten real du'as and citations like "Quran 2:196". They were removed
     * rather than paraphrased — these are the ritual steps of Umrah, and
     * inventing replacements would be worse than the defect.
     *
     * So a Dhivehi visitor reads the correct instructions in English. That
     * fallback is now per field rather than per guide: a step was two rows,
     * one per language, and a single missing Dhivehi row sent the *whole*
     * guide back to English. One row holds both languages, so the Dhivehi
     * shows wherever it exists and only the rest falls back.
     */
    private function guideStepsFor(string $locale): Collection
    {
        return GuideStep::published()->ordered()->get()
            ->each(fn (GuideStep $step) => $step->setLocale($locale));
    }

    public function guide()
    {
        $guideSteps = $this->guideStepsFor(app()->getLocale());

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

        $stripped = preg_replace('#^/(?:'.implode('|', SetLocale::SUPPORTED).')(?=/|$)#', '', $path, 1, $count);

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
        $guideSteps = $this->guideStepsFor($locale);

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

        $guideSteps = $this->guideStepsFor($locale)
            ->map(function ($step) {
                return [
                    'step_number' => $step->step_number,
                    'title' => $step->title,
                    'summary' => $step->summary,
                    'details' => $step->details,
                    'dua_text' => $step->dua_text,
                    // Lists, always. A translated attribute with nothing
                    // stored for this locale reads back as an empty string,
                    // and this endpoint has always promised an array.
                    'fiqh_notes' => $step->fiqh_notes_list,
                    'checklist' => $step->checklist_items,
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
