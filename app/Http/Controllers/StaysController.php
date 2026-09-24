<?php

namespace App\Http\Controllers;

use App\Support\Services as ServiceRegistry;
use Illuminate\Contracts\View\View;

/**
 * The Stays line's placeholder pages — §15.3 (Phase 8.2).
 *
 * Guesthouses, island holidays and rooms in Malé have nothing to list yet;
 * Phases 9–11 build the partners, properties and booking flow. Until then,
 * a service that is `on` or `coming_soon` shows what the line is and takes
 * an enquiry — decision 6 of §15.2: "coming_soon shows the pages, takes
 * enquiries, and takes no money." `on` shows the same page today, because
 * there is nothing more to sell than there is to enquire about; the phase
 * that gives a service something real to list is the same phase that
 * teaches its controller to show it.
 *
 * EnsureServiceEnabled has already turned away anyone an `off` switch says
 * should not be here.
 */
class StaysController extends Controller
{
    public function guesthouses(): View
    {
        return $this->comingSoon(
            'stays_guesthouses',
            __('messages.Rihla markets a hand-picked set of guesthouses across the Maldives on behalf of the people who run them. Tell us where and when you are thinking of, and we will send you what is available.'),
        );
    }

    public function islandHolidays(): View
    {
        return $this->comingSoon(
            'stays_island_holidays',
            __('messages.Short island holidays for Maldivian families — a weekend away, arranged the way our Umrah groups already are. Tell us which island and when, and we will put together a plan.'),
        );
    }

    public function rooms(): View
    {
        return $this->comingSoon(
            'stays_rooms',
            __('messages.Nightly rooms in Malé, booked and paid for online. Tell us your dates and we will let you know as soon as booking opens.'),
        );
    }

    private function comingSoon(string $service, string $blurb): View
    {
        return view('pages.stays-coming-soon', [
            'label' => __(ServiceRegistry::catalogue()[$service]['label']),
            'blurb' => $blurb,
        ]);
    }
}
