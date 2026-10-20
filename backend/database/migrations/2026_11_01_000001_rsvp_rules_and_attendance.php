<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Standing replies ("always yes on Wednesday trainings") and the actual
     * attendance at trainings, recorded afterwards by trainers.
     */
    public function up(): void
    {
        Schema::create('rsvp_rules', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('person_id', 'persons');
            $table->tenantForeign('team_id', 'teams', nullable: true);
            $table->tenantForeign('series_id', 'event_series', nullable: true);
            $table->string('event_type', 16)->nullable();
            $table->json('weekdays')->nullable();
            $table->string('status', 8);
            $table->string('reason', 255)->nullable();
            $table->tenantForeign('created_by', 'tenant_members');
            $table->timestamps();
            $table->index(['tenant_id', 'person_id']);
        });

        Schema::table('event_invitees', function (Blueprint $table) {
            // null = not recorded: then an acceptance counts as present.
            $table->boolean('attended')->nullable()->after('source');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('attendance_recorded_at')->nullable()->after('task_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('attendance_recorded_at'));
        Schema::table('event_invitees', fn (Blueprint $table) => $table->dropColumn('attended'));
        Schema::dropIfExists('rsvp_rules');
    }
};
