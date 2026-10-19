<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guests may be limited to positions that are still missing (by the
     * Positions-Soll, else the team's ideal squad). Halls get their address as separate fields; the old
     * one-line address is kept as street where it cannot be split.
     */
    public function up(): void
    {
        foreach (['events', 'event_series'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('guests_positions_only')->default(false)->after('guests_allowed');
            });
        }

        // Ideal squad of a team: how many players per position (e.g. mixed without libero).
        Schema::table('teams', function (Blueprint $table) {
            $table->json('ideal_positions')->nullable()->after('default_max_participants');
        });

        Schema::table('halls', function (Blueprint $table) {
            $table->string('street', 120)->nullable()->after('address');
            $table->string('house_number', 20)->nullable()->after('street');
            $table->string('postal_code', 10)->nullable()->after('house_number');
            $table->string('city', 80)->nullable()->after('postal_code');
        });

        foreach (DB::table('halls')->whereNotNull('address')->get(['id', 'address']) as $hall) {
            DB::table('halls')->where('id', $hall->id)->update(self::split((string) $hall->address));
        }
    }

    public function down(): void
    {
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn('ideal_positions'));
        Schema::table('halls', fn (Blueprint $table) => $table->dropColumn(['street', 'house_number', 'postal_code', 'city']));
        foreach (['events', 'event_series'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('guests_positions_only'));
        }
    }

    /**
     * "Kuhbergstraße 12, 89077 Ulm" → street, house number, postal code, city.
     *
     * @return array<string, string|null>
     */
    private static function split(string $address): array
    {
        if (preg_match('/^\s*(.+?)\s+(\d+\s*[a-zA-Z]?(?:\s*[-\/]\s*\d+\s*[a-zA-Z]?)?)\s*,\s*(\d{4,5})\s+(.+?)\s*$/u', $address, $m)) {
            return ['street' => mb_substr($m[1], 0, 120), 'house_number' => mb_substr($m[2], 0, 20), 'postal_code' => $m[3], 'city' => mb_substr($m[4], 0, 80)];
        }

        return ['street' => mb_substr(trim($address), 0, 120), 'house_number' => null, 'postal_code' => null, 'city' => null];
    }
};
