<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('default_locale', 8)->default('de');
            $table->string('timezone', 64)->default('Europe/Berlin');
            $table->unsignedTinyInteger('season_start_month')->default(7);
            $table->string('mail_from_name')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 128)->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('tenants');
    }
};
