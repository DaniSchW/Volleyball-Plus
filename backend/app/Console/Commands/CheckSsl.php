<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Security\SslCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
            // Admin notification by mail follows once admin accounts exist (phase 1).
            Log::critical('SSL self-check failed', $result);

            return self::FAILURE;
        }

        $this->info(sprintf('SSL ok for %s, valid until %s (%d days).', $result['host'], $result['valid_until'], $result['days_left']));

        return self::SUCCESS;
    }
}
