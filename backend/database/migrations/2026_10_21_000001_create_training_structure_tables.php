<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // League tree, maintained by the admins (differs per regional association).
        Schema::create('leagues', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('parent_id', 'leagues', nullable: true, onDelete: 'restrict');
            $table->string('name', 80);
            $table->unsignedSmallInteger('level');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->tenantForeign('league_id', 'leagues', nullable: true, onDelete: 'restrict');
        });

        // Guests from lower teams who may join the host team's trainings.
        Schema::create('cross_nominations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('host_team_id', 'teams');
            $table->tenantForeign('person_id', 'persons');
            $table->tenantForeign('home_team_id', 'teams');
            $table->boolean('active')->default(true);
            $table->tenantForeign('invited_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
            $table->unique(['host_team_id', 'person_id']);
        });

        Schema::create('training_groups', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('team_id', 'teams');
            $table->string('name', 80);
            $table->timestamps();
        });

        Schema::create('training_group_members', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('training_group_id', 'training_groups');
            $table->tenantForeign('person_id', 'persons');
            $table->unique(['training_group_id', 'person_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('cross_nomination_opens_at')->nullable()->after('max_participants');
            $table->tenantForeign('training_group_id', 'training_groups', nullable: true, onDelete: 'restrict');
        });

        Schema::table('event_series', function (Blueprint $table) {
            $table->tenantForeign('training_group_id', 'training_groups', nullable: true, onDelete: 'restrict');
        });

        Schema::create('referee_licenses', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('person_id', 'persons');
            $table->string('level', 20);
            $table->string('license_number', 40)->nullable();
            $table->date('valid_until');
            $table->timestamps();
            $table->unique('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referee_licenses');
        Schema::table('event_series', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'training_group_id']);
            $table->dropColumn('training_group_id');
        });
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'training_group_id']);
            $table->dropColumn(['training_group_id', 'cross_nomination_opens_at']);
        });
        Schema::dropIfExists('training_group_members');
        Schema::dropIfExists('training_groups');
        Schema::dropIfExists('cross_nominations');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'league_id']);
            $table->dropColumn('league_id');
        });
        Schema::dropIfExists('leagues');
    }
};
