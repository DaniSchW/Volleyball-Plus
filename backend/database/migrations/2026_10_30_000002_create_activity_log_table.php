<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Who changed what – shown to admins, kept for 7 days. */
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            // No foreign key: entries outlive deleted members (shown as "deleted").
            $table->char('actor_id', 26)->nullable();
            $table->string('action', 16);
            $table->string('subject_type', 40);
            $table->char('subject_id', 26)->nullable();
            $table->string('label', 200);
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
