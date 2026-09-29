<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A room with no price for the audience asking — §16.3 decision 6.
 *
 * Not "unavailable": the nights may be free. The host has simply not
 * offered this room to this audience, and quoting it at zero or at the
 * other audience's price would be inventing a number.
 */
class NotSoldToAudience extends RuntimeException {}
