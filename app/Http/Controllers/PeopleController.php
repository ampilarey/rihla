<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\View\View;

/**
 * Who travels with you.
 *
 * A first-time pilgrim choosing between two operators is largely deciding
 * whether they trust whoever will be standing beside them at the miqat. The
 * plan puts it plainly: pilgrims choose people, not packages — and nobody in
 * this market publishes who those people are.
 */
class PeopleController extends Controller
{
    public function index(): View
    {
        $people = Person::published()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy('role');

        return view('people.index', [
            'leaders' => $people->get(Person::ROLE_TOUR_LEADER) ?? collect(),
            'scholars' => $people->get(Person::ROLE_SCHOLAR) ?? collect(),
        ]);
    }
}
