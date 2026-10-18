<?php

namespace App\Support\Install;

/**
 * The app counts as installed once it has an application key – the web
 * installer writes it into .env as its very last step. Without a key the
 * app cannot encrypt anything, so every other route is unusable anyway.
 */
class Installation
{
    public static function isInstalled(): bool
    {
        return (string) config('app.key') !== '';
    }
}
