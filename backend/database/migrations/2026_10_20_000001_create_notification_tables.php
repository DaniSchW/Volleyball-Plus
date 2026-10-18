<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Browser push subscriptions per device (global: one login may be in several departments).
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('endpoint', 500);
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key', 255);
            $table->string('auth_token', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        // Only deviations from the defaults are stored.
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('tenant_member_id', 'tenant_members');
            $table->string('category', 32);
            $table->boolean('push');
            $table->boolean('email');
            $table->timestamps();
            $table->unique(['tenant_member_id', 'category']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('reminder_sent_at')->nullable()->after('status');
        });

        // Notices are announced once they become visible (may be scheduled ahead).
        Schema::table('notices', function (Blueprint $table) {
            $table->dateTime('notified_at')->nullable()->after('visible_until');
        });
        DB::table('notices')->update(['notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('notices', fn (Blueprint $table) => $table->dropColumn('notified_at'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('reminder_sent_at'));
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('push_subscriptions');
    }
};
