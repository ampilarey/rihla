<?php

namespace App\Support;

use App\Models\Document;
use App\Models\NusukPermit;
use App\Models\Payment;
use App\Models\VisaApplication;

/**
 * The words a customer reads for a status a member of staff set.
 *
 * ## Why this exists rather than `__('messages.'.$status)`
 *
 * The first version of the portal built its keys by concatenation, and
 * `TranslationTest` rejected it — correctly. A concatenated key cannot be
 * checked by anything: the scanner sees `messages.payment_status.` and the
 * rest is whatever the database happens to hold. The day a status gains a
 * value, a pilgrim's own booking page renders `messages.payment_status.x`
 * at them, and no test in this repository would have noticed.
 *
 * Every arm here is a whole literal key, so the guard can see all of them,
 * and every `match` has a `default`, so an unknown status is a vague
 * sentence rather than a raw key.
 *
 * ## The words are not the staff words
 *
 * "Awaiting review" is a queue state; "we are checking it" is what is
 * actually happening to a person's money. A pilgrim reading their own page
 * is not reading an admin screen.
 */
final class PortalWords
{
    public static function paymentStatus(string $status): string
    {
        return match ($status) {
            Payment::PENDING => __('messages.We have recorded this'),
            Payment::AWAITING_REVIEW => __('messages.We are checking it'),
            Payment::SUCCEEDED => __('messages.Received'),
            Payment::FAILED => __('messages.We could not find this one'),
            Payment::CANCELLED => __('messages.Cancelled'),
            default => __('messages.We are looking into this'),
        };
    }

    public static function documentStatus(string $status): string
    {
        return match ($status) {
            Document::PENDING => __('messages.With us, not checked yet'),
            Document::VERIFIED => __('messages.Checked'),
            Document::REJECTED => __('messages.We need a better copy'),
            default => __('messages.We are looking into this'),
        };
    }

    public static function visaStatus(?string $status): string
    {
        return match ($status) {
            VisaApplication::NOT_STARTED, null => __('messages.Not started'),
            VisaApplication::PREPARING => __('messages.Being prepared'),
            VisaApplication::SUBMITTED => __('messages.Submitted'),
            VisaApplication::ISSUED => __('messages.Issued'),
            VisaApplication::REJECTED => __('messages.Refused'),
            VisaApplication::CANCELLED => __('messages.Cancelled'),
            default => __('messages.We are looking into this'),
        };
    }

    public static function permitStatus(?string $status): string
    {
        return match ($status) {
            NusukPermit::NOT_STARTED, null => __('messages.Not started'),
            NusukPermit::REQUESTED => __('messages.Requested'),
            NusukPermit::ISSUED => __('messages.Issued'),
            NusukPermit::REFUSED => __('messages.Refused'),
            NusukPermit::CANCELLED => __('messages.Cancelled'),
            default => __('messages.We are looking into this'),
        };
    }

    /**
     * What is still missing, in a pilgrim's words.
     *
     * @param  list<string>  $unmet
     * @return list<string>
     */
    public static function requirements(array $unmet): array
    {
        return array_map(static fn (string $requirement): string => match ($requirement) {
            TravelReadiness::PASSPORT => __('messages.your passport'),
            TravelReadiness::VISA => __('messages.your visa'),
            TravelReadiness::PERMIT => __('messages.your Umrah permit'),
            default => $requirement,
        }, $unmet);
    }
}
