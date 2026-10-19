<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One cash book per team and one for the department; created on first use.
        Schema::create('cash_books', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('kind', 12);
            $table->tenantForeign('team_id', 'teams', nullable: true);
            // "department" or the team id: one book each (unique also without team).
            $table->string('book_key', 26);
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'book_key']);
        });

        // Purely informative bookkeeping: no payments. Corrections by cancelling.
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('cash_book_id', 'cash_books');
            $table->date('booked_on');
            $table->bigInteger('amount_cents');
            $table->string('category', 60)->nullable();
            $table->string('description', 300);
            $table->tenantForeign('person_id', 'persons', nullable: true, onDelete: 'restrict');
            $table->tenantForeign('created_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->dateTime('cancelled_at')->nullable();
            $table->tenantForeign('cancelled_by', 'tenant_members', nullable: true, onDelete: 'restrict');
            $table->timestamps();
            $table->index(['cash_book_id', 'booked_on']);
        });

        Schema::create('halls', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->string('name', 120);
            $table->string('address', 255)->nullable();
            $table->unsignedTinyInteger('courts')->default(1);
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
        });

        // Times the department may use the hall.
        Schema::create('hall_slots', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('hall_id', 'halls');
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });

        // Which team trains when in the hall. Overlaps only warn.
        Schema::create('hall_bookings', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->tenantKey();
            $table->tenantForeign('hall_id', 'halls');
            $table->tenantForeign('team_id', 'teams');
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedTinyInteger('court')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['hall_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hall_bookings');
        Schema::dropIfExists('hall_slots');
        Schema::dropIfExists('halls');
        Schema::dropIfExists('cash_entries');
        Schema::dropIfExists('cash_books');
    }
};
