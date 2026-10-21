<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dates of a series whose event was deleted – the generator must not create them again. */
    public function up(): void
    {
        Schema::table('event_series', function (Blueprint $table) {
            $table->json('excluded_dates')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('event_series', fn (Blueprint $table) => $table->dropColumn('excluded_dates'));
    }
};
