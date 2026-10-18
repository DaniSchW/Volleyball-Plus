<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Origin of an imported match (calendar UID or match number), so a new
        // import of the updated file changes the match instead of adding it twice.
        Schema::table('events', function (Blueprint $table) {
            $table->string('import_key', 191)->nullable()->after('training_group_id');
            $table->index(['tenant_id', 'import_key']);
        });

        // The team's name in the association's files (e.g. "SSV Ulm 1846 2").
        Schema::table('teams', function (Blueprint $table) {
            $table->string('import_name', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('import_name'));
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'import_key']);
            $table->dropColumn('import_key');
        });
    }
};
