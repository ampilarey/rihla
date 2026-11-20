<?php

namespace Modules\Stays\Exceptions;

use RuntimeException;

/** A payout Finance cannot record, with the reason in words — §16 Phase 16. */
class PayoutRefused extends RuntimeException {}
