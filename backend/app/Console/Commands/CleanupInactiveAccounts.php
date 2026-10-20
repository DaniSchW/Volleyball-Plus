<?php

namespace App\Console\Commands;

use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Setting;
use App\Support\Privacy\AccountDeletion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Accounts not used for two years (config platform.inactive_after_days)
 * get an e-mail: the account will be deleted in 14 days. Logging in
 * keeps it. Without that, it is deleted like by the person themselves.
 * Superadmins and platform admins are left alone; the last admin of a
 * club is not deleted (shown in the superadmin's account list).
 */
class CleanupInactiveAccounts extends Command
{
    protected $signature = 'app:cleanup-inactive-accounts';

    protected $description = 'Warn and later delete accounts that have not been used for a long time';

    public const LAST_RUN = 'app.cleanup_inactive_accounts.last_run';

    public static function isDue(?CarbonImmutable $now = null): bool
    {
        $last = Setting::read(self::LAST_RUN);

        return $last === null || CarbonImmutable::parse($last)->addHours(6)->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    public function handle(AccountDeletion $deletion): int
    {
        $now = CarbonImmutable::now();
        $inactiveSince = $now->subDays((int) config('platform.inactive_after_days'));
        $noticeDays = (int) config('platform.deletion_notice_days');
        $candidates = fn () => Account::query()
            ->where('is_platform_admin', false)
            ->whereNotIn('email', config('platform.superadmins'))
            ->where('email', 'not like', '%@invalid')
            ->where('last_active_at', '<', $inactiveSince);

        $warned = 0;
        foreach ($candidates()->whereNull('deletion_warned_at')->limit(200)->get() as $account) {
            $date = $now->addDays($noticeDays)->locale($account->locale)->isoFormat('LL');
            Mail::to($account->email)->locale($account->locale)->send(new NotificationMail(
                null,
                __('account.inactive.title', [], $account->locale),
                __('account.inactive.body', ['date' => $date, 'days' => $noticeDays, 'years' => intdiv((int) config('platform.inactive_after_days'), 365)], $account->locale),
                rtrim((string) config('app.url'), '/').'/',
                false,
            ));
            $account->forceFill(['deletion_warned_at' => $now])->save();
            $warned++;
        }

        $deleted = 0;
        foreach ($candidates()->where('deletion_warned_at', '<=', $now->subDays($noticeDays))->limit(100)->get() as $account) {
            try {
                $deletion->delete($account);
                $deleted++;
            } catch (ValidationException $e) {
                // E.g. the last admin of a club – stays, the superadmin sees it in the list.
                Log::info('Inactive account not deleted', ['account' => $account->id, 'reason' => $e->getMessage()]);
            }
        }

        Setting::write(self::LAST_RUN, $now->toIso8601String());
        $this->info("Warned: {$warned}, deleted: {$deleted}");

        return self::SUCCESS;
    }
}
