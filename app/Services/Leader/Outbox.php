<?php

namespace App\Services\Leader;

use App\Models\Departure;
use App\Models\Incident;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Traveller;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applying writes a leader made on a phone with no signal — §6.3.
 *
 * §6.3 says the portal "must work offline — connectivity in transit is
 * unreliable; design for queued writes and sync". The phone keeps an
 * ordered outbox and posts it when a signal comes back. Everything hard
 * about that lands here.
 *
 * ## A replay must not become a second record
 *
 * The phone cannot tell a request that never arrived from one that arrived
 * and whose reply was lost, so it retries both. An incident therefore
 * carries a `client_uuid` generated on the phone before the first attempt:
 * every retry carries the same one, and the second is recognised and
 * returns the first incident rather than raising another.
 *
 * A roll-call mark needs no such column — it is keyed on
 * (roll_call_id, traveller_id), so replaying it sets the same value again.
 *
 * ## The leader's clock decides, not the order things arrive in
 *
 * A mark made at 09:00 and a correction made at 09:02 can reach the server
 * in either order, or the first can be retried after the second has landed.
 * So a mark carrying a time **earlier** than the one already stored is
 * discarded. Without that, a retry of a stale attempt silently undoes a
 * correction, and the count reads wrong with nothing to show why.
 *
 * ## Nothing is trusted because it came from the outbox
 *
 * Every item is checked against what this user may actually do and against
 * the departure it claims to belong to. An outbox is a request body like
 * any other: it reaches the server over the same HTTP and can say anything.
 */
final class Outbox
{
    public const MARK = 'mark';

    public const INCIDENT = 'incident';

    /** Applied, or refused with a reason the phone can show. */
    public const APPLIED = 'applied';

    public const DUPLICATE = 'duplicate';

    public const SUPERSEDED = 'superseded';

    public const REFUSED = 'refused';

    /**
     * Apply a batch, in the order the phone queued it.
     *
     * One transaction per item rather than one for the batch: a single bad
     * item must not throw away nine good ones, and a leader whose count
     * half-synced and then failed would have no way to tell which half.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{id: mixed, result: string, reason?: string}>
     */
    public function apply(array $items, User $actor): array
    {
        $results = [];

        foreach ($items as $item) {
            $results[] = DB::transaction(fn (): array => $this->applyOne($item, $actor));
        }

        return $results;
    }

    /** @param array<string, mixed> $item */
    private function applyOne(array $item, User $actor): array
    {
        $id = $item['id'] ?? null;
        $type = $item['type'] ?? null;

        return match ($type) {
            self::MARK => $this->applyMark($item, $actor) + ['id' => $id],
            self::INCIDENT => $this->applyIncident($item, $actor) + ['id' => $id],
            default => ['id' => $id, 'result' => self::REFUSED, 'reason' => 'Unknown kind of write.'],
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{result: string, reason?: string}
     */
    private function applyMark(array $item, User $actor): array
    {
        if (! $actor->can('attendance.update')) {
            return ['result' => self::REFUSED, 'reason' => 'You may not change a head count.'];
        }

        $rollCall = RollCall::find($item['roll_call_id'] ?? null);

        if ($rollCall === null) {
            return ['result' => self::REFUSED, 'reason' => 'That head count no longer exists.'];
        }

        if (! $this->mayWorkOn($actor, $rollCall->departure)) {
            return ['result' => self::REFUSED, 'reason' => 'That departure is not yours.'];
        }

        $traveller = Traveller::find($item['traveller_id'] ?? null);

        // Not merely "does this traveller exist": somebody on another trip
        // must not be markable on this one, whatever the phone posts.
        if ($traveller === null || ! $rollCall->expected()->contains('id', $traveller->getKey())) {
            return ['result' => self::REFUSED, 'reason' => 'That person is not on this departure.'];
        }

        $state = $item['state'] ?? null;

        if (! in_array($state, RollCallMark::STATES, true)) {
            return ['result' => self::REFUSED, 'reason' => 'That is not a state a mark can be in.'];
        }

        $markedAt = $this->timeFrom($item['marked_at'] ?? null);

        $existing = RollCallMark::where('roll_call_id', $rollCall->getKey())
            ->where('traveller_id', $traveller->getKey())
            ->first();

        // The leader's clock decides. A retry of a stale attempt arriving
        // after a correction would otherwise undo it silently.
        if ($existing !== null
            && $existing->marked_at !== null
            && $existing->marked_at->greaterThanOrEqualTo($markedAt)) {
            return ['result' => self::SUPERSEDED];
        }

        RollCallMark::updateOrCreate(
            ['roll_call_id' => $rollCall->getKey(), 'traveller_id' => $traveller->getKey()],
            [
                'state' => $state,
                'note' => $item['note'] ?? null,
                'marked_by' => $actor->getKey(),
                'marked_at' => $markedAt,
            ],
        );

        return ['result' => self::APPLIED];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{result: string, reason?: string}
     */
    private function applyIncident(array $item, User $actor): array
    {
        if (! $actor->can('incident.create')) {
            return ['result' => self::REFUSED, 'reason' => 'You may not raise an incident.'];
        }

        $uuid = $item['client_uuid'] ?? null;

        if (! is_string($uuid) || $uuid === '') {
            return ['result' => self::REFUSED, 'reason' => 'A queued incident needs an id made on the phone.'];
        }

        // The whole reason the column exists. Checked before anything is
        // written, so a retry is cheap and cannot race itself into two rows
        // — the unique index is the backstop if two arrive at once.
        if (Incident::where('client_uuid', $uuid)->exists()) {
            return ['result' => self::DUPLICATE];
        }

        $departure = Departure::find($item['departure_id'] ?? null);

        if ($departure === null || ! $this->mayWorkOn($actor, $departure)) {
            return ['result' => self::REFUSED, 'reason' => 'That departure is not yours.'];
        }

        $severity = $item['severity'] ?? null;

        if (! in_array($severity, Incident::SEVERITIES, true)) {
            return ['result' => self::REFUSED, 'reason' => 'That is not a severity.'];
        }

        $category = $item['category'] ?? Incident::OTHER;

        if (! in_array($category, Incident::CATEGORIES, true)) {
            $category = Incident::OTHER;
        }

        $summary = trim((string) ($item['summary'] ?? ''));

        if ($summary === '') {
            return ['result' => self::REFUSED, 'reason' => 'An incident needs a line saying what happened.'];
        }

        Incident::create([
            'client_uuid' => $uuid,
            'departure_id' => $departure->getKey(),
            'traveller_id' => $this->travellerOnDeparture($item['traveller_id'] ?? null, $departure),
            'severity' => $severity,
            'category' => $category,
            'summary' => $summary,
            'detail' => $item['detail'] ?? null,
            'location' => $item['location'] ?? null,
            'happened_at' => $this->timeFrom($item['happened_at'] ?? null),
        ]);

        return ['result' => self::APPLIED];
    }

    /**
     * Whether this user may work on this departure at all.
     *
     * Operations manages every trip. A tour leader gets the ones their
     * profile is assigned to — and an account with no linked profile gets
     * none, because the failure mode of a missing link has to be less
     * access rather than more.
     */
    private function mayWorkOn(User $actor, Departure $departure): bool
    {
        if ($actor->can('attendance.delete')) {
            return true;
        }

        $person = $actor->person;

        return $person !== null && $departure->tour_leader_id === $person->getKey();
    }

    /** A traveller id is only accepted if that person is actually on the trip. */
    private function travellerOnDeparture(mixed $travellerId, Departure $departure): ?int
    {
        if (blank($travellerId)) {
            return null;
        }

        $onIt = Traveller::query()
            ->whereKey($travellerId)
            ->whereHas(
                'bookingTravellers.booking',
                fn ($query) => $query->where('departure_id', $departure->getKey()),
            )
            ->exists();

        return $onIt ? (int) $travellerId : null;
    }

    /**
     * A time from the phone, or now.
     *
     * A phone's clock can be wrong, and one set weeks into the future would
     * pin a mark that nothing could ever supersede. So anything ahead of
     * the server is treated as now — the count still records, and the
     * ordering rule keeps working.
     */
    private function timeFrom(mixed $value): Carbon
    {
        if (! is_string($value) || $value === '') {
            return now();
        }

        try {
            $time = Carbon::parse($value);
        } catch (\Throwable) {
            return now();
        }

        return $time->isFuture() ? now() : $time;
    }
}
