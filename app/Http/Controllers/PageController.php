<?php

namespace App\Http\Controllers;

use App\Models\GuideStep;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;

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

    public function setLocale($locale)
    {
        if (in_array($locale, ['en', 'dv'])) {
            session(['app_locale' => $locale]);
        }

        return redirect()->back();
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
