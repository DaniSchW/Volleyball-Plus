<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            // team: shareable link that adds people to a team;
            // person: single-use link that connects an account to an existing profile.
            $table->string('kind', 8);
            $table->tenantForeign('team_id', 'teams', nullable: true);
            $table->string('role', 16)->nullable();
            $table->tenantForeign('person_id', 'persons', nullable: true);
            $table->string('relation', 16)->nullable();
            $table->char('token_hash', 64)->unique();
            // Encrypted with the app key so managers can show and copy the link again.
            $table->text('token_encrypted');
            $table->unsignedSmallInteger('max_uses')->nullable();
            $table->unsignedSmallInteger('use_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->tenantForeign('created_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
        });

        Schema::create('invitation_uses', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('invitation_id', 'invitations');
            $table->tenantForeign('tenant_member_id', 'tenant_members');
            $table->tenantForeign('person_id', 'persons', nullable: true, onDelete: 'restrict');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_uses');
        Schema::dropIfExists('invitations');
    }
};
