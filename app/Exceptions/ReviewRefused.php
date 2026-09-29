<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A review that cannot be taken, or a reply that cannot be changed —
 * §16.11. Its message is written for the person who tried.
 */
class ReviewRefused extends RuntimeException {}
