<?php

namespace App\Support\Install;

use InvalidArgumentException;

class EnvFile
{
    /**
     * @param  array<string, string|int|bool|null>  $values
     */
    public static function render(array $values): string
    {
        $lines = [];

        foreach ($values as $key => $value) {
            // null = leave unset, so the config default applies.
            if ($value === null) {
                continue;
            }

            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                throw new InvalidArgumentException("Invalid .env key: {$key}");
            }

            $lines[] = $key.'='.self::quote($value);
        }

        return implode("\n", $lines)."\n";
    }

    private static function quote(string|int|bool $value): string
    {
        if ($value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('.env values must be single-line.');
        }

        // Single quotes are taken literally by phpdotenv (no ${VAR} expansion).
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.addcslashes($value, '"\\$').'"';
    }
}
