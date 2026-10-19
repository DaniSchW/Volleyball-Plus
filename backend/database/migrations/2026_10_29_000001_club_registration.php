<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many clubs on one installation: clubs register themselves (status
     * "pending") and are approved by a platform admin. Login without a club
     * in the URL needs tokens without tenant.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('city', 80)->nullable()->after('name');
            $table->string('contact_name', 160)->nullable()->after('status');
            $table->string('contact_email')->nullable()->after('contact_name');
            $table->string('registration_note', 1000)->nullable()->after('contact_email');
            $table->timestamp('approved_at')->nullable()->after('registration_note');
        });

        Schema::table('auth_tokens', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });
        Schema::table('auth_tokens', function (Blueprint $table) {
            $table->char('tenant_id', 26)->nullable()->change();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['city', 'contact_name', 'contact_email', 'registration_note', 'approved_at']));
    }
};
