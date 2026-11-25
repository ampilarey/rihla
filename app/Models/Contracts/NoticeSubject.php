<?php

namespace App\Models\Contracts;

use App\Models\Customer;
use Carbon\CarbonInterface;

/**
 * Something a notice can be about whose person may have no customer record:
 * a boat seat or a cargo request typed in by a stranger, a trip somebody
 * asked us to plan. It says who to message, on what number, about what, and
 * for when — so the notice queue needs no branch per kind of thing.
 */
interface NoticeSubject
{
    public function noticeCustomer(): ?Customer;

    /** The name they gave, used when there is no customer record. */
    public function noticeName(): string;

    /** The number they gave for this, customer or not. */
    public function noticePhone(): ?string;

    public function noticeReference(): string;

    public function noticeDate(): ?CarbonInterface;
}
