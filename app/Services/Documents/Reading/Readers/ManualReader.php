<?php

namespace App\Services\Documents\Reading\Readers;

use App\Services\Documents\Reading\Extraction;

/** Proposes nothing: staff type what the scan says. */
final class ManualReader implements Reader
{
    public function name(): string
    {
        return 'manual';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function read(string $bytes, string $mime, string $type): Extraction
    {
        return Extraction::empty($this->name());
    }
}
