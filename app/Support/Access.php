<?php

namespace App\Support;

/**
 * The vocabulary of authorisation: who staff are, and what they may do.
 *
 * Permissions are verbs, not screens, so a policy can ask the same question
 * however the action is reached — admin panel, console command or a future
 * API. Only verbs for functionality that exists are defined here. Booking,
 * refund, payment, visa and permit permissions belong with the features that
 * introduce them; defining them now would produce a list of inert strings
 * that look enforced and are not.
 */
final class Access
{
    // Roles — the ten real Rihla functions. Nine are staff; the tenth is
    // not, and that is the point of it.
    public const SUPER_ADMIN = 'Super Admin';

    public const OPERATIONS_MANAGER = 'Operations Manager';

    public const BOOKING_STAFF = 'Booking Staff';

    public const FINANCE = 'Finance';

    public const VISA_STAFF = 'Visa Staff';

    public const PILGRIM_SUPPORT = 'Pilgrim Support';

    public const CONTENT_MANAGER = 'Content Manager';

    public const TOUR_LEADER = 'Tour Leader';

    public const REPORTING = 'Reporting';

    /**
     * The scholar who signs off religious content — §6.4.
     *
     * Not a member of staff. They review the Knowledge Centre and nothing
     * else: no bookings, no customers, no money, no website. §6.4 makes
     * editorial review before publish non-negotiable, and a reviewer who
     * also holds the publish button is not a reviewer.
     */
    public const SCHOLAR = 'Scholar';

    /** @var list<string> */
    public const ROLES = [
        self::SUPER_ADMIN,
        self::OPERATIONS_MANAGER,
        self::BOOKING_STAFF,
        self::FINANCE,
        self::VISA_STAFF,
        self::PILGRIM_SUPPORT,
        self::CONTENT_MANAGER,
        self::TOUR_LEADER,
        self::REPORTING,
        self::SCHOLAR,
    ];

    /**
     * Permissions covering what the application can do today.
     *
     * `viewAny` is separate from `view` throughout because a list is a
     * different disclosure from a single record: Reporting may see that
     * trips exist without being able to open every one.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'admin.access',

        'trip.viewAny',
        'trip.view',
        'trip.create',
        'trip.update',
        'trip.delete',

        // The product Rihla sells, and each dated run of it. Content, which
        // is why both prefixes are named in $content below — a package
        // description is public-facing copy, edited by whoever edits the
        // rest of the site.
        'package.viewAny',
        'package.view',
        'package.create',
        'package.update',
        'package.delete',

        'departure.viewAny',
        'departure.view',
        'departure.create',
        'departure.update',
        'departure.delete',

        // Group leaders and scholars. Content: a profile is public-facing
        // copy, edited by whoever edits the rest of the site.
        'person.viewAny',
        'person.view',
        'person.create',
        'person.update',
        'person.delete',

        // The blog. Content, like everything else a reader sees.
        'article.viewAny',
        'article.view',
        'article.create',
        'article.update',
        'article.delete',

        // Bookings. Deliberately no `booking.create` and no
        // `booking.delete`: a booking is created by the checkout flow, and a
        // booking is a financial record that is cancelled — a status, with a
        // row saying who and why — never deleted. Defining verbs for neither
        // would produce permissions that look enforced and gate nothing.
        'booking.viewAny',
        'booking.view',
        'booking.update',

        // The people who book and pay. No delete for the same reason: a
        // customer with bookings cannot be removed, and the database refuses
        // it.
        'customer.viewAny',
        'customer.view',
        'customer.update',

        // The document wallet. `download` is a separate verb from `view`
        // on purpose: seeing that a passport has been collected is a
        // different disclosure from pulling the scan, and the second is the
        // one that ends up in somebody's downloads folder. No delete —
        // [R-8] keeps every version, and a wallet you can quietly empty is
        // not an audit trail.
        'document.viewAny',
        'document.view',
        'document.create',
        'document.update',
        'document.download',

        // Visa applications (§5.4a). Deliberately its own prefix, not
        // shared with Nusuk permits: they are different authorisations from
        // different systems, and the roles that will hold them may diverge.
        // No delete — an application is a record of something submitted to a
        // government, and a refusal that can be removed is evidence that can
        // be removed.
        'visa.viewAny',
        'visa.view',
        'visa.create',
        'visa.update',

        // Nusuk permits (§5.4b). Its own prefix rather than sharing the
        // visa one [R-4]: they are different authorisations from different
        // systems, and the role that chases a Saudi permit portal may not
        // be the role that files embassy paperwork. No delete — a refusal
        // that can be removed is evidence that can be removed.
        'permit.viewAny',
        'permit.view',
        'permit.create',
        'permit.update',

        // Recording in Nusuk that a departure's accommodation and transport
        // are entered. The prerequisite gate for requesting any permit, and
        // a property of the dated run rather than of a person.
        'departure.nusuk',

        // The departure board (§8.2). Its own permission because of what it
        // aggregates: money outstanding across a departure's bookings, and
        // how many travellers are missing a passport, a visa or a permit.
        // Reading the departure record is not the same disclosure.
        'departure.board',

        // Money received against a booking (§5.3). `download` is a separate
        // verb for the same reason it is on documents: a bank slip carries
        // an account number and a name, and seeing that a payment exists is
        // a different disclosure from pulling the image.
        //
        // `reconcile` is separate from `update` on purpose. Anybody taking
        // the booking can record that a customer says they have paid;
        // deciding that the money is actually in is a finance decision, and
        // the two being one permission is how an unchecked slip becomes a
        // confirmed booking.
        'payment.viewAny',
        'payment.view',
        'payment.create',
        'payment.update',
        'payment.download',
        'payment.reconcile',
        'payment.refund',

        // Enquiries — §8.1's minimal CRM. No delete: an enquiry that was
        // lost is the record of a customer this operator did not win, and
        // that is the most useful thing in the table.
        'enquiry.viewAny',
        'enquiry.view',
        'enquiry.create',
        'enquiry.update',
        // Handing one to somebody else. Separate from `update` because
        // "who owns this" is a supervisor's decision at this size, and an
        // enquiry everybody can reassign is one nobody owns.
        'enquiry.assign',

        // The rest of §8.1. `quotation.*` has no delete verb for the same
        // reason `enquiry` has none: a quotation somebody declined is the
        // record of a price this operator could not win on, and that is
        // the most useful row in the table. A sent quotation is superseded
        // rather than edited, so `update` only ever touches a draft.
        'quotation.viewAny',
        'quotation.view',
        'quotation.create',
        'quotation.update',
        // Putting a price in front of a customer, and taking their answer.
        // Separate from writing one, because the number somebody is
        // committed to is not the same decision as the number somebody
        // drafted.
        'quotation.send',

        // Follow-up tasks. `assign` is separate for the same reason
        // `enquiry.assign` is: work everybody can hand around is work
        // nobody owns.
        'task.viewAny',
        'task.view',
        'task.create',
        'task.update',
        'task.assign',
        'task.delete',

        // Facts about a customer that outlive any one lead — tags,
        // preferences, who referred them.
        'customer.tag',

        // What a departure costs to run, and the margin that comes out of
        // it — §8.4. `cost.*` is Finance's and Operations', and the report
        // is a separate verb because "what did this journey make?" is a
        // number the office should not have to hand out to read.
        //
        // No delete: a cost somebody entered and then thought better of is
        // corrected, and a margin that changed because a row vanished is
        // one nobody can explain.
        'cost.viewAny',
        'cost.view',
        'cost.create',
        'cost.update',
        'profit.view',

        // The executive dashboard — §8.5, §10.5. One verb, because the
        // screen is a single disclosure: whoever may read the conversion
        // rate may read the seat-fill rate. The margin row on it is gated
        // separately by `profit.view` above, since a reporting analyst who
        // should see how many enquiries convert has no business reading
        // what each pilgrim left behind.
        'kpi.view',

        // The staff drafting assistant — §9.6. A verb, not a screen: it is
        // "may have a machine write a first draft for me", and it is
        // separate from the permissions on the things being drafted because
        // a draft is never sent by the assistant. The person still needs
        // `enquiry.update` or `announcement.create` to do anything with it.
        'draft.use',

        // Rooming (§8.2). No delete verb for an assignment: taking somebody
        // out of a room is an update to the rooming list, and a separate
        // permission for it would only ever be granted alongside update.
        'rooming.viewAny',
        'rooming.view',
        'rooming.update',

        // Incidents on the ground (§8.3, §6.5). No delete verb, and none is
        // ever added: an incident report that can be removed is evidence
        // that can be removed — the same reasoning that kept `delete` off
        // visa applications and Nusuk permits. `resolve` is separate from
        // `update` because closing one is a statement that it is over.
        'incident.viewAny',
        'incident.view',
        'incident.create',
        'incident.update',
        'incident.assign',
        'incident.resolve',

        // Attendance (§8.3). A head count at a moment where somebody could
        // be left behind. `delete` exists here and nowhere near incidents:
        // a count started against the wrong departure is a mis-click with
        // no evidential value, not a record of what happened to anybody.
        'attendance.viewAny',
        'attendance.view',
        'attendance.create',
        'attendance.update',
        'attendance.delete',

        // The daily operations log (§8.3). What happened ordinarily — the
        // coach was late, the hotel moved the group. Things that went
        // *wrong* are incidents and carry a severity and an owner.
        'opslog.viewAny',
        'opslog.view',
        'opslog.create',
        'opslog.update',

        // Announcements (§6.2). Group-level news, read by the pilgrim and
        // by whoever they have given a family link to. `publish` is its own
        // verb because writing one and putting it in front of forty
        // families are different acts, and the second is the one that
        // cannot be taken back.
        'announcement.viewAny',
        'announcement.view',
        'announcement.create',
        'announcement.update',
        'announcement.publish',
        'announcement.delete',

        // Emergency broadcasts (§6.5). `send` is separate from `create` for
        // the same reason publishing is separate from writing, only more
        // so: this reaches every household on a departure at once and
        // cannot be unsent. No delete verb — what was sent in an emergency
        // is the record of what was said, and a record that can be removed
        // is not one.
        'broadcast.viewAny',
        'broadcast.view',
        'broadcast.create',
        'broadcast.update',
        'broadcast.send',

        // Notices (§11.2). Who has not been told what, and who still owes
        // us something. No create verb: a notice is raised from a record
        // that already exists, never typed — the moment somebody can write
        // one by hand, the portal starts carrying claims nothing backs.
        'notice.viewAny',
        'notice.view',
        'notice.handle',

        // The Knowledge Centre (§7.1) and the scholar review it cannot
        // ship without (§6.4).
        //
        // `review` is a scholar's judgement about content; `publish` is the
        // office's decision about timing. They are separate verbs because
        // one button doing both would make the scholar the publisher, and
        // because the whole point of §6.4 is that neither can be skipped.
        //
        // No delete verb: a withdrawn article keeps its reason, and a page
        // that vanished for no recorded cause is one nobody can explain.
        'knowledge.viewAny',
        'knowledge.view',
        'knowledge.create',
        'knowledge.update',
        'knowledge.review',
        'knowledge.publish',

        // The Ziyarah Guide (§7.2). The same six verbs, and the same
        // reason for the last two being separate: a location page makes
        // the same kind of religious claim an article does, and a
        // reviewer who can also publish performs the check on themselves.
        //
        // A separate resource from `knowledge` rather than a reuse of it,
        // because the two are edited by different people: the office that
        // writes trip content owns the places pilgrims visit, and the
        // Knowledge Centre is a library. Sharing one permission would mean
        // granting one to grant the other.
        'ziyarah.viewAny',
        'ziyarah.view',
        'ziyarah.create',
        'ziyarah.update',
        'ziyarah.review',
        'ziyarah.publish',

        // The Learning Academy (§7.3). The same six verbs again, and the
        // same reason for the last two being apart: a module teaching
        // somebody how to perform a rite is a religious claim, and the
        // reader is about to act on it rather than merely read it.
        //
        // A learning *path* carries no editorial gate of its own — it is an
        // ordering of modules a scholar has already signed off — so
        // publishing one is an office act under `learning.publish`.
        'learning.viewAny',
        'learning.view',
        'learning.create',
        'learning.update',
        'learning.review',
        'learning.publish',

        // Ask a Scholar (§6.4). Three verbs, not six: nobody in the office
        // writes a question — a pilgrim does — and nobody deletes one,
        // because a question that vanished is one the person who asked it
        // is still waiting on.
        //
        // `answer` is the scholar's. `publish` is the office's, and it is
        // gated on the asker's consent besides: a question is a private
        // message unless the person who wrote it said otherwise.
        'question.viewAny',
        'question.view',
        'question.answer',
        'question.publish',

        'media.viewAny',
        'media.view',
        'media.create',
        'media.update',
        'media.delete',

        'guide.viewAny',
        'guide.view',
        'guide.create',
        'guide.update',
        'guide.delete',

        'heroBanner.viewAny',
        'heroBanner.view',
        'heroBanner.create',
        'heroBanner.update',
        'heroBanner.delete',

        'whySection.viewAny',
        'whySection.view',
        'whySection.update',

        'whyFeature.create',
        'whyFeature.update',
        'whyFeature.delete',

        'setting.view',
        'setting.update',

        // Reading the audit trail is an oversight function, not a content one.
        'audit.viewAny',

        // Managing staff accounts and the roles they hold. Deliberately in no
        // role's set: Super Admin holds it through Gate::before, and granting
        // it to anyone else is a decision for whoever needs it rather than a
        // default. A role that can hand out roles can hand out its own.
        'user.viewAny',
        'user.view',
        'user.create',
        'user.update',
        'user.delete',

        // The Pulse dashboard. In no role's set, for the same reason as
        // `user.*` and stated here rather than left to be noticed: Pulse
        // shows the SQL of slow queries, the file and line of every
        // exception, and the names and email addresses of whoever was
        // signed in at the time. That is an engineering surface, not an
        // operations one, and nobody's job at Rihla needs it. Super Admin
        // holds it through Gate::before; granting it to anyone else is a
        // decision someone makes deliberately.
        'pulse.view',
    ];

    /**
     * What each role may do.
     *
     * Super Admin is deliberately absent: it is granted everything through a
     * Gate::before hook rather than by holding every permission, so a
     * permission added later cannot silently leave it locked out.
     *
     * Roles whose work does not exist yet map to an empty set. They are still
     * defined so staff can be assigned to them now, and so the gap between a
     * job function and what the software supports is visible rather than
     * implied.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        // Defined by inclusion, not exclusion. It used to be "everything
        // except settings and the audit log", and the day `user.*` was added
        // to PERMISSIONS the Content Manager and Operations Manager silently
        // gained the ability to create staff accounts and hand out roles —
        // including their own. A list that grows by default grants by
        // default.
        $content = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => in_array(
                strtok($permission, '.'),
                ['trip', 'package', 'departure', 'person', 'article', 'media', 'guide', 'heroBanner', 'whySection', 'whyFeature'],
                true,
            )
                // `departure.nusuk` is not content. It records a dealing
                // with a Saudi system, and the Content Manager — who edits
                // the website — has no business asserting one. The content
                // set is defined by inclusion precisely so that adding a
                // verb under an existing prefix cannot grant it by accident,
                // and this is that case arriving.
                && $permission !== 'departure.nusuk'
                // `departure.board` is the same case, a second time. The
                // board carries money outstanding and how many travellers
                // are missing documents; editing the website is not a
                // reason to see either. Inclusion by prefix would have
                // handed it over silently.
                && $permission !== 'departure.board',
        ));

        // Bookings and the customers attached to them. A separate set from
        // $content on purpose: this is where passport numbers, phone numbers
        // and money live, and nobody gets it by editing the website.
        $bookings = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => in_array(strtok($permission, '.'), ['booking', 'customer'], true),
        ));

        $documents = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'document',
        ));

        $visas = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'visa',
        ));

        $visasReadOnly = ['visa.viewAny', 'visa.view'];

        $permits = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'permit',
        ));

        $permitsReadOnly = ['permit.viewAny', 'permit.view'];

        $payments = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'payment',
        ));

        // Seeing that money was received, without the slip and without the
        // power to say it is good.
        $paymentsReadOnly = ['payment.viewAny', 'payment.view'];

        $enquiries = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'enquiry',
        ));

        // Works the enquiries they are given; does not hand them out.
        $enquiriesWithoutAssigning = ['enquiry.viewAny', 'enquiry.view', 'enquiry.create', 'enquiry.update'];

        $quotations = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'quotation',
        ));

        // Drafts a price and cannot commit the operator to it.
        $quotationsWithoutSending = [
            'quotation.viewAny', 'quotation.view', 'quotation.create', 'quotation.update',
        ];

        $quotationsReadOnly = ['quotation.viewAny', 'quotation.view'];

        $tasks = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'task',
        ));

        // Works the list and cannot hand work to somebody else.
        $tasksWithoutAssigning = ['task.viewAny', 'task.view', 'task.create', 'task.update'];

        $costs = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'cost',
        ));

        $costsReadOnly = ['cost.viewAny', 'cost.view'];

        $rooming = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'rooming',
        ));

        $roomingReadOnly = ['rooming.viewAny', 'rooming.view'];

        $incidents = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'incident',
        ));

        $incidentsReadOnly = ['incident.viewAny', 'incident.view'];

        // Raises one and adds to the narrative; does not decide it is over
        // and does not hand it to somebody else. Both of those are the
        // office's, and a leader closing their own incident from the
        // coach is how a serious one stops being followed up.
        $incidentsFromTheGround = [
            'incident.viewAny', 'incident.view', 'incident.create', 'incident.update',
        ];

        $attendance = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'attendance',
        ));

        $attendanceReadOnly = ['attendance.viewAny', 'attendance.view'];

        $opsLog = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'opslog',
        ));

        $opsLogReadOnly = ['opslog.viewAny', 'opslog.view'];

        $announcements = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'announcement',
        ));

        $knowledge = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'knowledge',
        ));

        // Writes it; neither reviews nor publishes it. The Content Manager
        // owns the website's words, and religious content is the one place
        // where owning the words is not enough.
        $knowledgeWithoutSignOff = [
            'knowledge.viewAny', 'knowledge.view', 'knowledge.create', 'knowledge.update',
        ];

        // A scholar reads the queue and signs off. They do not publish, and
        // they do not write the site's marketing.
        $knowledgeReview = ['knowledge.viewAny', 'knowledge.view', 'knowledge.review'];

        $ziyarah = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'ziyarah',
        ));

        // Writes the location pages; neither reviews nor publishes them.
        $ziyarahWithoutSignOff = [
            'ziyarah.viewAny', 'ziyarah.view', 'ziyarah.create', 'ziyarah.update',
        ];

        $ziyarahReview = ['ziyarah.viewAny', 'ziyarah.view', 'ziyarah.review'];

        $learning = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'learning',
        ));

        $learningWithoutSignOff = [
            'learning.viewAny', 'learning.view', 'learning.create', 'learning.update',
        ];

        $learningReview = ['learning.viewAny', 'learning.view', 'learning.review'];

        $questions = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'question',
        ));

        // The scholar reads the queue and answers. Putting an answer in
        // front of other people is an office decision and needs the
        // asker's consent, so it is not theirs.
        $questionsAnswerOnly = ['question.viewAny', 'question.view', 'question.answer'];

        $notices = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'notice',
        ));

        $noticesReadOnly = ['notice.viewAny', 'notice.view'];

        $broadcasts = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => strtok($permission, '.') === 'broadcast',
        ));

        // Drafts one from the ground and cannot send it. A leader in the
        // middle of an incident is the worst-placed person to decide that
        // forty households should hear about it.
        $broadcastsWithoutSending = [
            'broadcast.viewAny', 'broadcast.view', 'broadcast.create', 'broadcast.update',
        ];

        // Writes and edits, but does not publish. A tour leader drafts what
        // happened; the office decides it goes in front of forty families.
        $announcementsWithoutPublishing = [
            'announcement.viewAny', 'announcement.view',
            'announcement.create', 'announcement.update',
        ];

        // Seeing that a document exists, without pulling the file.
        $documentsReadOnly = ['document.viewAny', 'document.view'];

        $bookingsReadOnly = array_values(array_filter(
            $bookings,
            fn (string $permission) => str_contains($permission, '.view'),
        ));

        // Built from $content, not from every permission. Filtering the whole
        // list for '.view' swept up `user.view` and `user.viewAny` the moment
        // those existed, which handed the Reporting role a list of every
        // staff account and their email addresses.
        $readOnly = array_values(array_filter(
            $content,
            fn (string $permission) => str_contains($permission, '.view'),
        ));

        return [
            self::SUPER_ADMIN => [],

            // Runs the operation: all content, plus settings and bookings.
            self::OPERATIONS_MANAGER => array_merge(
                ['admin.access', 'setting.view', 'setting.update', 'audit.viewAny'],
                $bookings,
                $documents,
                $visas,
                $permits,
                $payments,
                $enquiries,
                $quotations,
                $tasks,
                ['customer.tag'],
                $costs,
                ['profit.view', 'kpi.view', 'draft.use'],
                $rooming,
                $incidents,
                $attendance,
                $opsLog,
                $announcements,
                $broadcasts,
                $notices,
                array_diff($knowledge, ['knowledge.review']),
                array_diff($ziyarah, ['ziyarah.review']),
                array_diff($learning, ['learning.review']),
                array_diff($questions, ['question.answer']),
                ['departure.nusuk', 'departure.board'],
                $content,
            ),

            // Owns the public-facing content, but not the site's settings —
            // social links and contact details are an operations decision.
            self::CONTENT_MANAGER => array_merge(
                // The drafting assistant writes itinerary and announcement
                // wording, which is this role's work. It cannot publish
                // anything it wrote — the draft goes into a form a person
                // still has to save.
                ['admin.access', 'draft.use'],
                $content,
                // Drafts knowledge articles and sends them for review.
                // Owning the website's words is not enough to sign off
                // religious content (§6.4).
                $knowledgeWithoutSignOff,
                $ziyarahWithoutSignOff,
                $learningWithoutSignOff,
            ),

            // Read-only across the board.
            // Read-only across content, plus the audit trail — reporting on
            // who changed what is the one oversight function this role has.
            // It used to hold that by accident: `audit.viewAny` contains
            // '.view', and $readOnly was built by matching that substring
            // against every permission. Stated deliberately now.
            // Reporting sees that bookings exist and how many; it does not
            // open one. `viewAny` without `view` is the whole reason those
            // are separate permissions — a list is a different disclosure
            // from a record holding a passport number.
            self::REPORTING => array_merge(
                // The dashboard is what this role is for. Not `profit.view`:
                // an analyst reading conversion and seat-fill rates has no
                // need of the margin, and §8.5's board says so on the screen
                // rather than leaving a blank where a row would be.
                ['admin.access', 'audit.viewAny', 'booking.viewAny', 'kpi.view'],
                $readOnly,
            ),

            // Trip-facing staff need to read trips to do their job, but the
            // trip listing is content, not theirs to change.
            // Carries the rooming list on the trip: it is the document they
            // stand at a hotel desk with.
            self::TOUR_LEADER => array_merge(
                ['admin.access', 'trip.viewAny', 'trip.view'],
                $roomingReadOnly,
                // They are the person standing there when it happens. An
                // incident that has to wait for the office to open is one
                // recorded from memory two days later, if at all.
                $incidentsFromTheGround,
                // The head count is their job, not the office's: they are
                // the one standing at the coach door. Deleting a count is
                // not, because a count deleted from the coach is a count
                // nobody can check.
                ['attendance.viewAny', 'attendance.view', 'attendance.create', 'attendance.update'],
                // And they write the day up.
                $opsLog,
                // They draft what the families should hear; the office
                // decides it goes out.
                $announcementsWithoutPublishing,
                $broadcastsWithoutSending,
            ),

            // Takes and manages bookings. Reads the product to do it —
            // which departure, which room, what it costs — but does not
            // write it: prices and descriptions are content.
            self::BOOKING_STAFF => array_merge(
                // Drafts a reply to an enquiry, then edits and sends it
                // themselves. §9.6's human in the loop is this person.
                ['admin.access', 'draft.use'],
                $bookings,
                // Collects documents and sends them back to the customer who
                // mislaid them, so it uploads and downloads. Verifying is
                // Visa Staff's call, not theirs.
                ['document.viewAny', 'document.view', 'document.create', 'document.download'],
                // Reads the visa state to answer "when are we travelling,
                // then?" without being able to move it — that is Visa
                // Staff's call.
                $visasReadOnly,
                $permitsReadOnly,
                // The board tells them which departure needs chasing, which
                // is most of what taking bookings is. Reporting is
                // deliberately *not* given it: the board carries money
                // outstanding, and that role does not hold payments at all.
                ['package.viewAny', 'package.view', 'departure.viewAny', 'departure.view', 'departure.board'],
                // Takes the phone call where somebody says they have paid,
                // so it records the claim and can pull the slip back up for
                // the customer who mislaid it. It cannot decide the money is
                // in: that is `payment.reconcile`, and it is Finance's.
                ['payment.viewAny', 'payment.view', 'payment.create', 'payment.download'],
                // The people who answer the phone are the people who work
                // the enquiries — and the chasing list, which is the same
                // job by another name.
                $enquiriesWithoutAssigning,
                // And the price they quote, and the reminders they set
                // themselves. Sending is theirs: at this size the person
                // on the phone is the person who commits to the number,
                // and a quotation that needs a supervisor to send is one
                // that gets read out from a draft instead.
                $quotations,
                $tasksWithoutAssigning,
                ['customer.tag'],
                $notices,
                // Reads the rooming to answer "who am I sharing with?";
                // rearranging it is operations' job.
                $roomingReadOnly,
            ),

            // Reads the review queue and signs articles off. Nothing else
            // — not bookings, not customers, not money, not the website.
            // A reviewer who can also publish is not a reviewer (§6.4), so
            // `knowledge.publish` is deliberately absent here.
            self::SCHOLAR => array_merge(
                ['admin.access'],
                $knowledgeReview,
                $ziyarahReview,
                $learningReview,
                $questionsAnswerOnly,
            ),

            // Reconciles payments, so it reads bookings and who they belong
            // to. Editing one is booking staff's job; when refunds and
            // payment records exist, this is the role that gets them.
            // Reconciles payments, so it reads bookings and who they belong
            // to. Editing a booking is booking staff's job. Payments are
            // now this role's whole reason to exist: it records them, pulls
            // the slips, decides whether the money is in, and issues
            // refunds. Nobody else holds `payment.reconcile`.
            // The board's money line is this role's job: which departures
            // are flying with a balance outstanding, and how much.
            self::FINANCE => array_merge(
                ['admin.access', 'departure.board'],
                $bookingsReadOnly,
                $payments,
                // The other half of the arithmetic. Finance is the role
                // that knows what was paid out, and §8.4's per-journey
                // profitability is the report this operator does not have
                // today — so this is the role that owns it.
                $costs,
                ['profit.view', 'kpi.view'],
                // Reads what was quoted, to reconcile against what came in.
                $quotationsReadOnly,
            ),

            // Answers the phone. Needs to find a booking and read it back to
            // whoever is calling, and nothing more until the pilgrim portal
            // and the CRM give it something to change.
            self::PILGRIM_SUPPORT => array_merge(
                ['admin.access'],
                $bookingsReadOnly,
                // Can say "yes, we have your passport" without being able to
                // pull the scan. That is the whole reason download is a
                // separate verb.
                $documentsReadOnly,
                $visasReadOnly,
                $permitsReadOnly,
                $paymentsReadOnly,
                // Answering the phone is where most enquiries come from.
                $enquiriesWithoutAssigning,
                // Reads a quotation to answer "what were we quoted?" on the
                // phone, and cannot write or send one: committing the
                // operator to a price is not a reception job.
                $quotationsReadOnly,
                $tasksWithoutAssigning,
                $roomingReadOnly,
                // Takes the call from a family at home asking what happened.
                $incidentsReadOnly,
                $attendanceReadOnly,
                $opsLogReadOnly,
                // Reads them, because the phone call is often "I saw the
                // announcement, what does it mean". The same for a
                // broadcast, only the call is more urgent.
                ['announcement.viewAny', 'announcement.view'],
                ['broadcast.viewAny', 'broadcast.view'],
                $notices,
            ),

            // The documents are the job: collecting them, checking them and
            // sending them to a Saudi system. Visa applications and Nusuk
            // permits are the next slice; this role already holds the wallet
            // those workflows run on.
            self::VISA_STAFF => array_merge(
                ['admin.access'],
                $documents,
                $visas,
                $permits,
                // Records the accommodation and transport the permit gate
                // needs, because this is the role sitting in front of Nusuk.
                ['departure.nusuk', 'departure.viewAny', 'departure.view'],
                $bookingsReadOnly,
            ),
        ];
    }
}
