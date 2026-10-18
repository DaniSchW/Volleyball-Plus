<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Logo levels 1 and 2: department and team.
        Schema::table('tenants', function (Blueprint $table) {
            $table->char('logo_file_id', 26)->nullable()->after('name');
        });
        Schema::table('teams', function (Blueprint $table) {
            $table->tenantForeign('logo_file_id', 'files', nullable: true, onDelete: 'restrict');
        });

        // Logo level 3: opposing clubs, reused across match days.
        Schema::create('opponents', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('name', 120);
            $table->tenantForeign('logo_file_id', 'files', nullable: true, onDelete: 'restrict');
            $table->timestamps();
        });

        // Weekly training series; single events are generated from it.
        Schema::create('event_series', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('team_id', 'teams');
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->json('weekdays'); // ISO 1 = Monday … 7 = Sunday
            $table->unsignedTinyInteger('interval_weeks')->default(1);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('location_name', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedInteger('rsvp_deadline_minutes')->nullable(); // before start
            $table->unsignedSmallInteger('max_participants')->nullable();
            $table->date('generated_until')->nullable();
            $table->tenantForeign('created_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('type', 16);            // training, match, extra, helper
            $table->string('response_mode', 8);    // rsvp, info
            $table->string('scope', 8);            // team, tenant
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('meet_at')->nullable();
            $table->dateTime('rsvp_deadline')->nullable();
            $table->string('location_name', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->unsignedSmallInteger('max_participants')->nullable();
            $table->tenantForeign('series_id', 'event_series', nullable: true, onDelete: 'restrict');
            $table->boolean('series_exception')->default(false);
            $table->tenantForeign('opponent_id', 'opponents', nullable: true, onDelete: 'restrict');
            $table->boolean('is_home')->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->tenantForeign('created_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
            $table->index(['tenant_id', 'starts_at', 'id']);
        });

        Schema::create('event_teams', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('event_id', 'events');
            $table->tenantForeign('team_id', 'teams');
            $table->unique(['event_id', 'team_id']);
        });

        // Who is invited – materialised from the teams, kept in sync with the roster.
        Schema::create('event_invitees', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('event_id', 'events');
            $table->tenantForeign('person_id', 'persons');
            $table->string('source', 16)->default('team');
            $table->unique(['event_id', 'person_id']);
            $table->index(['tenant_id', 'person_id']);
        });

        Schema::create('event_responses', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('event_id', 'events');
            $table->tenantForeign('person_id', 'persons');
            $table->string('status', 8); // yes, no, maybe
            $table->string('reason', 500)->nullable();
            $table->tenantForeign('responded_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->dateTime('responded_at');
            $table->timestamps();
            $table->unique(['event_id', 'person_id']);
            $table->index(['event_id', 'status']);
        });

        // Append-only history: who answered what and when (statistics).
        Schema::create('event_response_logs', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('event_id', 'events');
            $table->tenantForeign('person_id', 'persons');
            $table->string('old_status', 8)->nullable();
            $table->string('new_status', 8);
            $table->tenantForeign('responded_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->boolean('after_deadline')->default(false);
            $table->timestamps();
        });

        // Notice area at the top of the start page.
        Schema::create('notices', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('scope', 8); // team, tenant
            $table->tenantForeign('team_id', 'teams', nullable: true);
            $table->string('title', 120);
            $table->text('body')->nullable();
            $table->dateTime('visible_from');
            $table->dateTime('visible_until');
            $table->tenantForeign('created_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
        });

        // Calendar subscription (ICS) per member, via secret URL.
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('tenant_member_id', 'tenant_members');
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->timestamps();
            $table->unique(['tenant_id', 'tenant_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('notices');
        Schema::dropIfExists('event_response_logs');
        Schema::dropIfExists('event_responses');
        Schema::dropIfExists('event_invitees');
        Schema::dropIfExists('event_teams');
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_series');
        Schema::dropIfExists('opponents');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'logo_file_id']);
            $table->dropColumn('logo_file_id');
        });
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('logo_file_id'));
    }
};
