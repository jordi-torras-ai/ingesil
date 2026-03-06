<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a pgvector value as a list<float> in PHP, and as "[...]" text in SQL.
 */
class PgVector implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                if (is_numeric($v)) {
                    $out[] = (float) $v;
                }
            }

            return $out ?: null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = trim($text, "[] \t\n\r\0\x0B");
        if ($text === '') {
            return null;
        }

        $parts = preg_split('/\s*,\s*/', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if ($part === '' || ! is_numeric($part)) {
                continue;
            }
            $out[] = (float) $part;
        }

        return $out ?: null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_array($value)) {
            throw new \InvalidArgumentException('Embedding value must be an array of floats.');
        }

        $floats = [];
        foreach ($value as $v) {
            if (! is_numeric($v)) {
                continue;
            }
            $floats[] = (float) $v;
        }

        if ($floats === []) {
            return null;
        }

        // pgvector input format: "[0.1, 0.2, ...]"
        return '['.implode(',', array_map(static fn (float $f): string => rtrim(rtrim(sprintf('%.10F', $f), '0'), '.'), $floats)).']';
    }
}

