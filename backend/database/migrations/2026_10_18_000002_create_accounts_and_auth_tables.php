<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global login identities (one per e-mail address, across departments).
        Schema::create('accounts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('email')->unique();
            $table->string('locale', 8)->default('de');
            $table->boolean('is_platform_admin')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // One-time magic links (login and invitation confirmation).
        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 16);
            $table->char('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->index(['email', 'created_at']);
        });

        // Login sessions (90 days, extended on use).
        Schema::create('account_sessions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');
            $table->string('user_agent', 255)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_sessions');
        Schema::dropIfExists('auth_tokens');
        Schema::dropIfExists('accounts');
    }
};
