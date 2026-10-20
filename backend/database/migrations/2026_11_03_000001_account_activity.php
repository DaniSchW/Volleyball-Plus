<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When an account was last used (login or app use) and when it was told
     * about its deletion for inactivity.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('last_active_at')->nullable()->after('last_login_at');
            $table->timestamp('deletion_warned_at')->nullable()->after('last_active_at');
        });

        foreach (DB::table('accounts')->get(['id', 'created_at', 'last_login_at']) as $account) {
            $seen = DB::table('account_sessions')->where('account_id', $account->id)->max('last_seen_at');
            $dates = array_filter([$account->created_at, $account->last_login_at, $seen]);
            DB::table('accounts')->where('id', $account->id)->update(['last_active_at' => $dates === [] ? now() : max($dates)]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn(['last_active_at', 'deletion_warned_at']));
    }
};
