<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('name');
            $table->string('gender', 8);
            $table->boolean('is_youth')->default(false);
            // league_id and logo_file_id follow with the league tree and file uploads.
            $table->unsignedSmallInteger('rank_in_league')->default(1);
            $table->unsignedSmallInteger('min_participants')->nullable();
            $table->unsignedSmallInteger('default_max_participants')->nullable();
            $table->string('cross_nomination_mode', 32)->default('immediately');
            $table->unsignedSmallInteger('cross_nomination_hours')->nullable();
            $table->boolean('decline_reason_required')->default(false);
            $table->boolean('maybe_reason_required')->default(false);
            $table->boolean('reasons_visible_to_teammates')->default(false);
            $table->string('color', 16)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
