<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Something a host tried at the desk that the stay cannot do — §16.10.
 *
 * An ordinary answer rather than a fault, so its message is written for the
 * person at reception and shown to them as it is.
 */
class DeskRefusal extends RuntimeException {}
