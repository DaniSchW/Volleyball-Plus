<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pictures, videos and documents in chats. Uploaded first, then sent
        // with a message; uploads never sent are removed after a day.
        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('chat_id', 'chats');
            $table->tenantForeign('message_id', 'chat_messages', nullable: true, onDelete: 'restrict');
            $table->tenantForeign('file_id', 'files', onDelete: 'restrict');
            $table->string('kind', 8);
            $table->string('name', 200);
            $table->tenantForeign('uploaded_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
    }
};
