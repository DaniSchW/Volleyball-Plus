<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Standing replies may end (e.g. at the end of the season); null = no end. */
    public function up(): void
    {
        Schema::table('rsvp_rules', function (Blueprint $table) {
            $table->date('valid_until')->nullable()->after('weekdays');
        });
    }

    public function down(): void
    {
        Schema::table('rsvp_rules', fn (Blueprint $table) => $table->dropColumn('valid_until'));
    }
};
