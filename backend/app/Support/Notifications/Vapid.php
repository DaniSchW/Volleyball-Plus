<?php

namespace App\Support\Notifications;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Minishlink\WebPush\VAPID as VapidKeys;

/**
 * Server keys for Web Push (VAPID). Created on first use and kept in the
 * settings table, the private key encrypted with the APP_KEY.
 */
class Vapid
{
    public const SETTING = 'push.vapid';

    /**
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    public static function keys(): array
    {
        $stored = Setting::read(self::SETTING);
        if (is_array($stored)) {
            try {
                return ['subject' => self::subject(), 'publicKey' => $stored['public'], 'privateKey' => Crypt::decryptString($stored['private'])];
            } catch (DecryptException) {
                // APP_KEY changed – new keys; devices subscribe again on their next visit.
            }
        }

        $keys = VapidKeys::createVapidKeys();
        Setting::write(self::SETTING, ['public' => $keys['publicKey'], 'private' => Crypt::encryptString($keys['privateKey'])]);

        return ['subject' => self::subject(), 'publicKey' => $keys['publicKey'], 'privateKey' => $keys['privateKey']];
    }

    public static function publicKey(): string
    {
        return self::keys()['publicKey'];
    }

    /** Contact for the push services: the site address, else the sender address. */
    private static function subject(): string
    {
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://') ? $url : 'mailto:'.config('mail.from.address');
    }
}
