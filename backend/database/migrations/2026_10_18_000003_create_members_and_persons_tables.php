<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('storage_key');
            $table->string('mime_type', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('purpose', 32);
            $table->char('uploaded_by', 26)->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_members', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->foreignUlid('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->boolean('is_admin')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'account_id']);
        });

        Schema::create('persons', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->unsignedSmallInteger('jersey_number')->nullable();
            $table->date('birthday')->nullable();
            $table->string('gender', 8);
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('street', 120)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('primary_position', 16)->nullable();
            $table->json('secondary_positions')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->unsignedSmallInteger('reach_height_cm')->nullable();
            $table->unsignedSmallInteger('jump_reach_cm')->nullable();
            $table->tenantForeign('avatar_file_id', 'files', nullable: true, onDelete: 'restrict');
            // Detail fields (phone, email, address, birthday) the person shares with all members.
            $table->json('shared_fields')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('person_accounts', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('person_id', 'persons');
            $table->tenantForeign('tenant_member_id', 'tenant_members');
            $table->string('relation', 16);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->unique(['person_id', 'tenant_member_id']);
        });

        Schema::create('minor_permissions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('person_id', 'persons');
            $table->boolean('own_login')->default(false);
            $table->boolean('can_rsvp')->default(false);
            $table->boolean('can_chat')->default(false);
            $table->boolean('can_take_tasks')->default(false);
            $table->boolean('can_vote_polls')->default(false);
            $table->json('editable_profile_fields')->nullable();
            $table->char('updated_by', 26)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'person_id']);
        });

        Schema::create('team_memberships', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('team_id', 'teams');
            $table->tenantForeign('person_id', 'persons');
            $table->string('role', 16);
            $table->date('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'person_id', 'role']);
        });

        Schema::table('teams', function (Blueprint $table) {
            // Teammates see who accepted/declined (names) or only the counts.
            $table->string('rsvp_visibility', 8)->default('names')->after('reasons_visible_to_teammates');
        });
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('rsvp_visibility'));
        Schema::dropIfExists('team_memberships');
        Schema::dropIfExists('minor_permissions');
        Schema::dropIfExists('person_accounts');
        Schema::dropIfExists('persons');
        Schema::dropIfExists('tenant_members');
        Schema::dropIfExists('files');
    }
};
