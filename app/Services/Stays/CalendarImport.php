<?php

namespace App\Services\Stays;

use App\Exceptions\CalendarFeedRefused;
use App\Models\BlockedDate;
use App\Models\CalendarFeed;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Other sites' calendars into blocked nights — §16 Phase 16.
 *
 * Each sync makes the room's `ical` blocks match the feed: nights the feed
 * names are blocked, `ical` nights it no longer names are released. A night
 * blocked by hand (`admin` or `partner`) is never touched, in either
 * direction. A feed that cannot be read leaves the last good blocks where
 * they are and says why: releasing every night because the other site was
 * down for a minute would sell rooms that are taken.
 *
 * **One room only.** A block closes the whole room type, so a feed on a
 * type with three rooms would close all three for one outside booking.
 * Such a feed is refused, not half-applied.
 */
class CalendarImport
{
    /** How far ahead a feed is read. Nothing in the past is written. */
    public const HORIZON_DAYS = 540;

    /** A single event longer than this is somebody's "closed for the season", read as a mistake. */
    public const LONGEST_EVENT = 365;

    public function __construct(private readonly CalendarFetcher $fetcher) {}

    /** Sync one feed. Returns the number of nights it now blocks; records the failure otherwise. */
    public function sync(CalendarFeed $feed): ?int
    {
        try {
            $room = $feed->roomType;

            if ($room === null || (int) $room->quantity !== 1) {
                throw new CalendarFeedRefused('A calendar can only close a room type with one room. Split the rooms into their own types first.');
            }

            $nights = $this->nights($this->fetcher->fetch((string) $feed->url));
        } catch (CalendarFeedRefused $refusal) {
            $feed->forceFill(['last_error' => mb_substr($refusal->getMessage(), 0, 255)])->save();

            return null;
        }

        DB::transaction(function () use ($feed, $nights): void {
            $ours = BlockedDate::query()
                ->where('room_type_id', $feed->room_type_id)
                ->where('source', BlockedDate::ICAL)
                ->pluck('date')
                ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
                ->all();

            $release = array_diff($ours, $nights);

            if ($release !== []) {
                BlockedDate::query()
                    ->where('room_type_id', $feed->room_type_id)
                    ->where('source', BlockedDate::ICAL)
                    ->where(function ($query) use ($release): void {
                        foreach ($release as $date) {
                            $query->orWhereDate('date', $date);
                        }
                    })
                    ->delete();
            }

            // A night already blocked by hand stays the hand's: skipped, not
            // replaced. Checked here, not left to the unique key, because
            // SQLite compares the stored text, and a date written by the
            // model (`Y-m-d 00:00:00`) is not the same text as `Y-m-d`.
            $taken = BlockedDate::query()
                ->where('room_type_id', $feed->room_type_id)
                ->pluck('date')
                ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
                ->all();

            $format = (new BlockedDate)->getDateFormat();
            $now = now();
            BlockedDate::query()->insertOrIgnore(array_map(fn (string $date): array => [
                'room_type_id' => $feed->room_type_id,
                'date' => CarbonImmutable::parse($date)->format($format),
                'source' => BlockedDate::ICAL,
                'note' => mb_substr('From '.$feed->label, 0, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values(array_diff($nights, $taken))));

            $feed->forceFill([
                'last_synced_at' => $now,
                'last_error' => null,
                'nights_blocked' => BlockedDate::query()
                    ->where('room_type_id', $feed->room_type_id)
                    ->where('source', BlockedDate::ICAL)
                    ->count(),
            ])->save();
        });

        return $feed->nights_blocked;
    }

    /** Open again every night this feed closed — when it is removed. */
    public function release(CalendarFeed $feed): void
    {
        BlockedDate::query()
            ->where('room_type_id', $feed->room_type_id)
            ->where('source', BlockedDate::ICAL)
            ->delete();
    }

    /**
     * The nights an iCal file names, from today to the horizon.
     *
     * Each event's DTSTART is its first night and DTEND the morning it
     * ends, as every booking site writes them; an event with no DTEND is
     * one night. Cancelled events are ignored.
     *
     * @return list<string> Y-m-d, sorted, unique
     */
    public function nights(string $ics): array
    {
        $today = CarbonImmutable::today();
        $horizon = $today->addDays(self::HORIZON_DAYS);
        $nights = [];

        foreach ($this->events($ics) as $event) {
            $start = $this->date($event['DTSTART'] ?? null);

            if ($start === null || strtoupper($event['STATUS'] ?? '') === 'CANCELLED') {
                continue;
            }

            $end = $this->date($event['DTEND'] ?? null) ?? $start->addDay();

            if ($end->lessThanOrEqualTo($start) || $start->diffInDays($end) > self::LONGEST_EVENT) {
                continue;
            }

            for ($night = $start->max($today); $night->lessThan($end) && $night->lessThan($horizon); $night = $night->addDay()) {
                $nights[$night->toDateString()] = true;
            }
        }

        $dates = array_keys($nights);
        sort($dates);

        return $dates;
    }

    /**
     * VEVENT blocks as name => value, lines unfolded (RFC 5545 §3.1) and
     * parameters dropped (`DTSTART;VALUE=DATE:20270310` → `DTSTART`).
     *
     * @return list<array<string, string>>
     */
    private function events(string $ics): array
    {
        $lines = explode("\n", preg_replace('/\r?\n[ \t]/', '', str_replace("\r\n", "\n", $ics)) ?? '');
        $events = [];
        $current = null;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if ($line === 'BEGIN:VEVENT') {
                $current = [];
            } elseif ($line === 'END:VEVENT') {
                if ($current !== null) {
                    $events[] = $current;
                }
                $current = null;
            } elseif ($current !== null && str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $current[strtoupper(explode(';', $name, 2)[0])] = trim($value);
            }
        }

        return $events;
    }

    /** `20270310` or `20270310T140000Z` → that calendar date; anything else → null. */
    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^(\d{8})/', $value, $match)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Ymd', $match[1]);

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
