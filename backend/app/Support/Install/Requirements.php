<?php

namespace App\Support\Install;

use Illuminate\Http\Request;

/**
 * Server checks shown by the web installer. Required checks block the
 * installation; optional ones only warn.
 */
class Requirements
{
    public const MIN_PHP = '8.3.0';

    public const RECOMMENDED_PHP = '8.5.0';

    private const EXTENSIONS = ['pdo_mysql', 'openssl', 'mbstring', 'ctype', 'fileinfo', 'tokenizer', 'curl', 'gd', 'intl'];

    /**
     * @return list<array{key: string, label: string, ok: bool, required: bool}>
     */
    public static function check(Request $request): array
    {
        $checks = [
            self::item('https', [], $request->secure(), true),
            self::item('php', ['min' => self::MIN_PHP, 'current' => PHP_VERSION], version_compare(PHP_VERSION, self::MIN_PHP, '>='), true),
            self::item('php_recommended', ['version' => self::RECOMMENDED_PHP], version_compare(PHP_VERSION, self::RECOMMENDED_PHP, '>='), false),
        ];

        foreach (self::EXTENSIONS as $extension) {
            $checks[] = self::item('ext', ['name' => $extension], extension_loaded($extension), true);
        }

        // Needed later for Web-Push encryption.
        $checks[] = self::item('ext', ['name' => 'bcmath / gmp'], extension_loaded('bcmath') || extension_loaded('gmp'), false);

        foreach (['storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $checks[] = self::item('writable', ['path' => 'backend/'.$dir], is_writable(base_path($dir)), true);
        }

        $checks[] = self::item('env_writable', ['path' => 'backend/.env'], self::envWritable(), false);

        return $checks;
    }

    /**
     * @param  list<array{ok: bool, required: bool}>  $checks
     */
    public static function passes(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    public static function envWritable(): bool
    {
        $path = app()->environmentFilePath();

        return is_file($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /**
     * @param  array<string, string>  $params
     * @return array{key: string, label: string, ok: bool, required: bool}
     */
    private static function item(string $key, array $params, bool $ok, bool $required): array
    {
        $label = __("install.checks.{$key}", $params);

        return compact('key', 'label', 'ok', 'required');
    }
}
