<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tasks of an event ("Fahrer", "Kuchen mitbringen"), each with a number of places.
        Schema::create('tasks', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('event_id', 'events');
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('slots')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('task_assignments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('task_id', 'tasks');
            $table->tenantForeign('person_id', 'persons');
            $table->tenantForeign('assigned_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamp('created_at')->nullable();
            $table->unique(['task_id', 'person_id']);
        });

        Schema::create('polls', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('scope', 8)->default('team');
            $table->boolean('multiple_choice')->default(false);
            $table->boolean('anonymous')->default(false);
            $table->boolean('allow_free_text')->default(false);
            $table->dateTime('closes_at')->nullable();
            $table->tenantForeign('created_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
        });

        Schema::create('poll_teams', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('poll_id', 'polls');
            $table->tenantForeign('team_id', 'teams');
            $table->unique(['poll_id', 'team_id']);
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('poll_id', 'polls');
            $table->string('label', 160);
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        // One row per chosen option; a free-text answer is a row without option.
        Schema::create('poll_votes', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('poll_id', 'polls');
            $table->tenantForeign('option_id', 'poll_options', nullable: true);
            $table->tenantForeign('person_id', 'persons');
            $table->tenantForeign('voted_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->string('free_text', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['poll_id', 'person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('poll_teams');
        Schema::dropIfExists('polls');
        Schema::dropIfExists('task_assignments');
        Schema::dropIfExists('tasks');
    }
};
