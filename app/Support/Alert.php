<?php

namespace App\Support;

/**
 * One thing worth somebody's attention — §8.5's smart alerts.
 *
 * A class rather than an array shape, for the reason {@see StudyPlanItem}
 * records: a `Collection`'s value template is invariant, so an array shape
 * inside one cannot be handed back from a typed method.
 *
 * ## Every alert names who acts and where
 *
 * An alert that says a thing is wrong and not what to do about it is a
 * notification, and notifications that cannot be acted on get ignored —
 * together with the ones that could have been. So each carries the screen
 * it is acted on and the permission that screen needs, and a reader only
 * sees the alerts they can do something about.
 *
 * That gating is not decoration. A tour leader shown "MVR 84,000 in
 * payments is waiting to be reconciled" has learned something about the
 * business and can do nothing with it.
 */
final class Alert
{
    /** Somebody should do this today. */
    public const URGENT = 'urgent';

    /** It will not spoil this week, but it is on the clock. */
    public const WORTH_KNOWING = 'worth_knowing';

    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        /** The condition, in one line. */
        public readonly string $headline,
        /** What is actually true, with the numbers in it. */
        public readonly string $detail,
        /** What to do, and on which screen. */
        public readonly string $action,
        public readonly string $url,
        /** The permission the acting screen needs. Null means everybody in the panel. */
        public readonly ?string $permission,
        /** How many things this alert is about, for ordering. */
        public readonly int $count,
    ) {}

    public function isUrgent(): bool
    {
        return $this->severity === self::URGENT;
    }

    public function tone(): string
    {
        return $this->isUrgent() ? 'danger' : 'warning';
    }

    public function severityLabel(): string
    {
        return $this->isUrgent() ? 'Today' : 'This week';
    }
}
