<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Reminder to everybody who took a task, once per event. */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('task_reminder_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('task_reminder_sent_at'));
    }
};
