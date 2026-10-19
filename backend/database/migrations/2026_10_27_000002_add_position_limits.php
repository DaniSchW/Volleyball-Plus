<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Positions-Soll: at most n accepted players per position, e.g. {"setter": 2, "libero": 1}. */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('position_limits')->nullable()->after('max_participants');
        });
        Schema::table('event_series', function (Blueprint $table) {
            $table->json('position_limits')->nullable()->after('max_participants');
        });
        // The position a player accepted for (counts against the limit).
        Schema::table('event_responses', function (Blueprint $table) {
            $table->string('as_position', 12)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('event_responses', fn (Blueprint $table) => $table->dropColumn('as_position'));
        Schema::table('event_series', fn (Blueprint $table) => $table->dropColumn('position_limits'));
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('position_limits'));
    }
};
