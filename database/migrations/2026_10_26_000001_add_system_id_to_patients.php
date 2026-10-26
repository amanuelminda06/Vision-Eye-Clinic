<?php

use App\Support\Patients\SystemId;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an auto-generated `system_id` (AA0001 … ZZ9999) alongside the optional,
 * manually entered Clinic ID in `id_no`.
 *
 * They are intentionally separate columns: the Clinic ID can be dropped later
 * once the system ID is adopted, without losing the generated identifiers.
 *
 * Existing patients are backfilled in creation order so every record gets an
 * ID before the unique index is added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('system_id', 6)->nullable()->after('id_no');
        });

        // Backfill in stable creation order. Done in PHP so it runs identically
        // on MySQL 8.0 and SQLite.
        $last = null;
        $ids = DB::table('patients')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $last = $last === null ? SystemId::first() : SystemId::next($last);

            DB::table('patients')
                ->where('id', $id)
                ->update(['system_id' => $last]);
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->unique('system_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique(['system_id']);
            $table->dropColumn('system_id');
        });
    }
};
