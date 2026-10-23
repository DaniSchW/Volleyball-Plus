<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hall is closed for some days (holidays, tournament, repairs): the
     * events there are cancelled or deleted, and series skip those days.
     */
    public function up(): void
    {
        Schema::create('hall_closures', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('hall_id', 'halls');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 200)->nullable();
            $table->string('mode', 10)->default('cancel'); // cancel | delete
            $table->timestamps();
            $table->index(['tenant_id', 'hall_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_closures');
    }
};
