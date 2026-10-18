<?php

namespace App\Console\Commands;

use App\Enums\MemberStatus;
use App\Mail\NotificationMail;
use App\Models\Setting;
use App\Models\TenantMember;
use App\Support\Security\SslCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckSsl extends Command
{
    protected $signature = 'security:check-ssl';

    protected $description = 'Check certificate, host name, expiry and HTTP→HTTPS redirect of this installation';

    public function handle(): int
    {
        $result = SslCheck::fromConfig()->run();

        Setting::write('security.ssl_check', $result);

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }
        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        if (! $result['ok']) {
            Log::critical('SSL self-check failed', $result);
            $this->mailAdmins($result);

            return self::FAILURE;
        }

        $this->info(sprintf('SSL ok for %s, valid until %s (%d days).', $result['host'], $result['valid_until'], $result['days_left']));

        return self::SUCCESS;
    }

    /**
     * Every admin login once (even if admin in several departments).
     *
     * @param  array<string, mixed>  $result
     */
    public function mailAdmins(array $result): void
    {
        $admins = TenantMember::withoutGlobalScopes()
            ->with(['account', 'tenant'])
            ->where('is_admin', true)
            ->where('status', MemberStatus::Active)
            ->get()
            ->unique('account_id');

        foreach ($admins as $admin) {
            $locale = $admin->account->locale ?: $admin->tenant->default_locale;
            $body = __('notifications.ssl.body', ['host' => $result['host']], $locale)."\n- ".implode("\n- ", $result['errors']);

            Mail::to($admin->account->email)->locale($locale)
                ->queue(new NotificationMail($admin->tenant, __('notifications.ssl.title', [], $locale), $body, settingsHint: false));
        }
    }
}
