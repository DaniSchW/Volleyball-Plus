<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One chat per team (created on first use) and custom chats of the admins.
        Schema::create('chats', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('kind', 8);
            $table->tenantForeign('team_id', 'teams', nullable: true);
            $table->string('name', 80)->nullable();
            $table->string('mode', 8)->default('flat');
            $table->tenantForeign('created_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
            $table->unique(['tenant_id', 'team_id']);
        });

        // Members of custom chats (team chats follow the roster).
        Schema::create('chat_members', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('chat_id', 'chats');
            $table->tenantForeign('tenant_member_id', 'tenant_members');
            $table->string('role', 12)->default('member');
            $table->unique(['chat_id', 'tenant_member_id']);
        });

        // Topics (sub-channels, Telegram style) for chats in "topics" mode.
        Schema::create('chat_topics', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('chat_id', 'chats');
            $table->string('name', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('chat_id', 'chats');
            $table->tenantForeign('topic_id', 'chat_topics', nullable: true, onDelete: 'restrict');
            $table->tenantForeign('author_id', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->text('body');
            $table->char('reply_to_id', 26)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            // Polling loads "everything after message x" (ULIDs sort by time).
            $table->index(['chat_id', 'topic_id', 'id']);
            $table->index(['chat_id', 'updated_at']);
        });

        // How far a member has read (per topic; '' = chat without topics).
        Schema::create('chat_read_states', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('chat_id', 'chats');
            $table->tenantForeign('tenant_member_id', 'tenant_members');
            $table->string('topic_key', 26)->default('');
            $table->char('last_read_message_id', 26)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unique(['chat_id', 'tenant_member_id', 'topic_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_read_states');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_topics');
        Schema::dropIfExists('chat_members');
        Schema::dropIfExists('chats');
    }
};
