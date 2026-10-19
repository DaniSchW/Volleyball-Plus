<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guests (cross-nominated players) either may accept a training or not –
     * with the same deadline as everybody. Trainings get an optional own
     * minimum of players (else the team's).
     */
    public function up(): void
    {
        foreach (['events', 'event_series'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('guests_allowed')->default(true)->after('max_participants');
                $table->unsignedSmallInteger('min_participants')->nullable()->after('max_participants');
            });
        }

        // Invitation link to the club itself (from "Mitglieder & Admins"), optionally as admin.
        Schema::table('invitations', function (Blueprint $table) {
            $table->boolean('grants_admin')->default(false)->after('relation');
            $table->string('kind', 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('grants_admin'));
        foreach (['events', 'event_series'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['guests_allowed', 'min_participants']));
        }
    }
};
