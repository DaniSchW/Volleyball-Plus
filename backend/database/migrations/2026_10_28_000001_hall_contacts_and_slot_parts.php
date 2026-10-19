<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contact for the hall (e.g. caretaker, booking office) – not necessarily a member.
        Schema::table('halls', function (Blueprint $table) {
            $table->string('contact_name', 120)->nullable()->after('courts');
            $table->string('contact_phone', 40)->nullable()->after('contact_name');
            $table->string('contact_email')->nullable()->after('contact_phone');
        });
        // Often only some parts are ours (the rest is used by another sport): list of parts, null = whole hall.
        Schema::table('hall_slots', function (Blueprint $table) {
            $table->json('courts')->nullable()->after('end_time');
        });
    }

    public function down(): void
    {
        Schema::table('hall_slots', fn (Blueprint $table) => $table->dropColumn('courts'));
        Schema::table('halls', fn (Blueprint $table) => $table->dropColumn(['contact_name', 'contact_phone', 'contact_email']));
    }
};
