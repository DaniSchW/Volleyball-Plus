<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Task templates ("Spieltag Damen 1": cash desk ×2, sales ×3, line judges
     * ×2, …) to take over into events, also automatically for a series.
     * Number of places becomes optional (null = as many as want).
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('slots')->nullable()->default(null)->change();
        });

        Schema::create('task_templates', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('team_id', 'teams', nullable: true);
            $table->string('name', 120);
            $table->json('items');
            $table->tenantForeign('created_by', 'tenant_members');
            $table->timestamps();
        });

        Schema::table('event_series', function (Blueprint $table) {
            $table->tenantForeign('task_template_id', 'task_templates', nullable: true, onDelete: 'restrict');
        });
    }

    public function down(): void
    {
        Schema::table('event_series', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'task_template_id']);
            $table->dropColumn('task_template_id');
        });
        Schema::dropIfExists('task_templates');
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedSmallInteger('slots')->default(1)->change();
        });
    }
};
