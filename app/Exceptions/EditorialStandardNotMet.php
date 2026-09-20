<?php

namespace App\Exceptions;

/**
 * An article did not meet the standard §7.1 requires.
 *
 * Its own exception rather than a generic one, so that the thing being
 * refused is legible at the call site and in a stack trace: this is not a
 * validation failure, it is the editorial standard doing its job.
 */
class EditorialStandardNotMet extends \RuntimeException {}
