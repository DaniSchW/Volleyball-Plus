<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document storage: for the whole department (team null, e.g. the club
     * registration PDF) or for one team (e.g. set-up instructions for match days).
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('team_id', 'teams', nullable: true);
            $table->string('folder', 80)->nullable();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->tenantForeign('file_id', 'files');
            $table->string('name', 200);
            $table->tenantForeign('uploaded_by', 'tenant_members');
            $table->timestamps();
            $table->index(['tenant_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
