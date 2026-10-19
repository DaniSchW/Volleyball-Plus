<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A team often uses only some parts of a hall (e.g. 2 of 3): list of parts instead of one. */
    public function up(): void
    {
        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->json('courts')->nullable()->after('end_time');
        });

        DB::table('hall_bookings')->whereNotNull('court')->orderBy('id')->each(function ($row) {
            DB::table('hall_bookings')->where('id', $row->id)->update(['courts' => json_encode([(int) $row->court])]);
        });

        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->dropColumn('court');
        });
    }

    public function down(): void
    {
        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->unsignedTinyInteger('court')->nullable()->after('end_time');
        });

        DB::table('hall_bookings')->whereNotNull('courts')->orderBy('id')->each(function ($row) {
            DB::table('hall_bookings')->where('id', $row->id)->update(['court' => json_decode($row->courts, true)[0] ?? null]);
        });

        Schema::table('hall_bookings', function (Blueprint $table) {
            $table->dropColumn('courts');
        });
    }
};
