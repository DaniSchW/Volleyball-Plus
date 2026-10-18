<?php

namespace App\Console\Commands;

use App\Models\ChatAttachment;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\Chat\AttachmentStore;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Removes chat uploads that were never sent with a message (after a day).
 */
class CleanupChatUploads extends Command
{
    protected $signature = 'app:cleanup-chat-uploads';

    protected $description = 'Delete chat uploads that were never sent';

    public const LAST_RUN = 'app.cleanup_chat_uploads.last_run';

    public static function isDue(?CarbonImmutable $now = null): bool
    {
        $last = Setting::read(self::LAST_RUN);

        return $last === null || CarbonImmutable::parse($last)->addHour()->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    public function handle(TenantContext $tenants, AttachmentStore $store): int
    {
        $removed = 0;
        foreach (Tenant::query()->cursor() as $tenant) {
            $removed += $tenants->run($tenant, function () use ($store) {
                $stale = ChatAttachment::query()->with('file')->whereNull('message_id')
                    ->where('created_at', '<', CarbonImmutable::now()->subDay())->get();
                $stale->each(fn (ChatAttachment $a) => $store->delete($a));

                return $stale->count();
            });
        }

        Setting::write(self::LAST_RUN, CarbonImmutable::now()->toIso8601String());
        $this->info("Removed: {$removed}");

        return self::SUCCESS;
    }
}
