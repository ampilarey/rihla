<?php

namespace App\Services\Documents\Reading;

/**
 * What a reader proposed: fields keyed by the traveller or document field
 * they map to, each with how sure the reader was.
 */
final class Extraction
{
    /**
     * @param  array<string, string>  $fields
     * @param  array<string, float>  $confidence
     */
    public function __construct(
        public readonly string $reader,
        public array $fields = [],
        public array $confidence = [],
        public ?string $rawText = null,
        public ?bool $mrzValid = null,
        public ?string $model = null,
        public ?string $error = null,
    ) {}

    public static function empty(string $reader, ?string $error = null): self
    {
        return new self($reader, error: $error);
    }

    public function set(string $field, ?string $value, float $confidence): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }

        $this->fields[$field] = trim($value);
        $this->confidence[$field] = max(0.0, min(1.0, $confidence));
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }
}
