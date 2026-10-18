<?php

namespace App\Support\Install;

/**
 * One-time code that proves the person running the web installer has FTP
 * access to the installation: it is only written to a file on the server.
 * Without it, anybody who finds a fresh installation first could point it
 * at their own database.
 */
class SetupCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function path(): string
    {
        return storage_path('app/setup-code.txt');
    }

    /** Relative path shown to the user (as seen in the FTP client). */
    public static function displayPath(): string
    {
        return 'backend/storage/app/setup-code.txt';
    }

    public static function ensure(): bool
    {
        if (is_file(self::path())) {
            return true;
        }

        $code = implode('-', array_map(
            fn () => implode('', array_map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)], range(1, 5))),
            range(1, 4),
        ));

        return @file_put_contents(self::path(), $code.PHP_EOL, LOCK_EX) !== false;
    }

    public static function matches(?string $input): bool
    {
        $stored = is_file(self::path()) ? self::normalize((string) file_get_contents(self::path())) : '';

        return $stored !== '' && hash_equals($stored, self::normalize((string) $input));
    }

    public static function forget(): void
    {
        @unlink(self::path());
    }

    private static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
