<?php

namespace App\Exceptions;

use RuntimeException;

/** A calendar link that cannot be, or was not, read — with the reason in words. §16 Phase 16. */
class CalendarFeedRefused extends RuntimeException {}
