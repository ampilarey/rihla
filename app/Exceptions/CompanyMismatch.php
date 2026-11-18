<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A row tried to join records of two different Umrah companies, or to
 * change the company it belongs to. Never caught: it is a defect, and the
 * write it stops is exactly the one decision 2 of the companies plan forbids.
 */
class CompanyMismatch extends RuntimeException {}
