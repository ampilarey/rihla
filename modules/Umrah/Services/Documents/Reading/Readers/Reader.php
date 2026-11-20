<?php

namespace Modules\Umrah\Services\Documents\Reading\Readers;

use Modules\Umrah\Services\Documents\Reading\Extraction;

interface Reader
{
    public function name(): string;

    public function isAvailable(): bool;

    public function read(string $bytes, string $mime, string $type): Extraction;
}
