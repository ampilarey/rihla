<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\LearningModule;
use App\Models\LearningPath;
use App\Models\ScholarQuestion;
use App\Models\Traveller;
use App\Services\Learning\Progress;
use App\Support\StudyPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Learning Academy inside the Pilgrim Portal — §7.3.
 *
 * ## Behind the portal session, not open to the web
 *
 * The plan is personal: it names what this pilgrim has and has not read,
 * and it is keyed to their departure. The module *text* is not secret and
 * could sit on the public site one day; the plan cannot, and mixing the two
 * behind one URL is how the personal half leaks.
 *
 * ## Whose progress
 *
 * The lead traveller on the booking. A portal session is a booking, and a
 * booking can carry a family — so this records the person who holds the
 * link, and says so on the page rather than pretending to track everyone
 * from one login. Per-person logins for a family are §6.2's problem and
 * are not solved by guessing here.
 */
class LearningController extends Controller
{
    public function __construct(private readonly Progress $progress) {}

    public function index(Request $request): View
    {
        $booking = $this->booking($request);

        return view('learning.index', [
            'booking' => $booking,
            'plan' => StudyPlan::build($booking->departure, $this->traveller($booking)),
            'paths' => LearningPath::published()
                ->with(['publishedModules'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
        ]);
    }

    /**
     * Reading a module is what marks it read.
     *
     * There is no "mark as complete" button: a button records that somebody
     * pressed a button, and opening the page is the closest thing this
     * system can honestly observe to somebody having read it.
     */
    public function show(Request $request, string $slug): View
    {
        $booking = $this->booking($request);

        $module = LearningModule::live()
            ->with(['quizQuestions.options', 'quizQuestions.references', 'references', 'reviewer', 'location'])
            ->where('slug', $slug)
            ->firstOrFail();

        $traveller = $this->traveller($booking);

        if ($traveller !== null) {
            $this->progress->markRead($traveller, $module);
        }

        return view('learning.show', [
            'booking' => $booking,
            'module' => $module,
            'completion' => $traveller === null
                ? null
                : $module->completions()->where('traveller_id', $traveller->getKey())->first(),
            'results' => null,
        ]);
    }

    public function submitQuiz(Request $request, string $slug): View
    {
        $booking = $this->booking($request);

        $module = LearningModule::live()
            ->with(['quizQuestions.options', 'quizQuestions.references', 'references', 'reviewer', 'location'])
            ->where('slug', $slug)
            ->firstOrFail();

        $traveller = $this->traveller($booking);

        // `validate()` returns only the keys it has rules for, so the
        // answers are read off the request directly — a lesson this
        // codebase already paid for once in the leader sync.
        $answers = is_array($request->input('answers')) ? $request->input('answers') : [];

        $outcome = $traveller === null
            ? null
            : $this->progress->recordQuiz($traveller, $module, $answers);

        return view('learning.show', [
            'booking' => $booking,
            'module' => $module,
            'completion' => $traveller === null
                ? null
                : $module->completions()->where('traveller_id', $traveller->getKey())->first(),
            'results' => $outcome,
        ]);
    }

    /**
     * Ask a Scholar — §6.4.
     *
     * On the learning pages rather than its own corner of the portal: the
     * moment somebody wants to ask is while they are reading, and a
     * question form two clicks away from the reading is one nobody uses.
     */
    public function questions(Request $request): View
    {
        $booking = $this->booking($request);

        return view('learning.questions', [
            'booking' => $booking,
            'questions' => ScholarQuestion::where('booking_id', $booking->getKey())
                ->with(['scholar', 'references'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function askQuestion(Request $request): RedirectResponse
    {
        $booking = $this->booking($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:4000'],
            // Consent, asked for at the moment of asking and never assumed
            // afterwards. A checkbox that is absent means no.
            'may_publish' => ['nullable', 'boolean'],
        ]);

        ScholarQuestion::create([
            'booking_id' => $booking->getKey(),
            'traveller_id' => $this->traveller($booking)?->getKey(),
            'body' => $data['body'],
            'locale' => app()->getLocale(),
            'may_publish' => (bool) ($data['may_publish'] ?? false),
        ]);

        return redirect()->route('learning.questions')
            ->with('status', __('messages.Your question has been sent. Somebody will come back to you.'));
    }

    private function booking(Request $request): Booking
    {
        /** @var Booking */
        return $request->attributes->get('portal_booking');
    }

    /**
     * The person whose progress this is.
     *
     * Null when the booking has no traveller recorded yet — early in the
     * flow that is normal, and the plan still renders with nothing marked
     * done, which is exactly what a new pilgrim sees.
     */
    private function traveller(Booking $booking): ?Traveller
    {
        return $booking->leadTraveller()?->traveller;
    }
}
