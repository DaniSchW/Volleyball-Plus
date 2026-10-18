<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Office content types are long, e.g.
    // application/vnd.openxmlformats-officedocument.presentationml.presentation (73 characters).
    public function up(): void
    {
        Schema::table('files', fn (Blueprint $table) => $table->string('mime_type', 120)->change());
    }

    public function down(): void
    {
        Schema::table('files', fn (Blueprint $table) => $table->string('mime_type', 64)->change());
    }
};
