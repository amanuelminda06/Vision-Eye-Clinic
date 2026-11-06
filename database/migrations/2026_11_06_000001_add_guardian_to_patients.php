<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Family links: a patient may point at another patient who acts as their
 * guardian (a parent or guardian — usually the person who brings a child in).
 *
 * - `guardian_id` is a soft reference with no foreign key, the same
 *   convention `created_by` follows: the app resolves the row itself and a
 *   plain ULID column keeps the migration portable across MySQL 8.0 and
 *   SQLite (the test suite).
 * - `relationship` records HOW the two records are linked; the only value
 *   this build writes is `guardian`.
 * - `phone_e164` stays nullable + unique: a linked child stores NULL, so a
 *   whole family may exist without duplicating the guardian's number while
 *   every real phone stays unique (NULLs are distinct in both engines).
 *
 * The guardian's number is read from the guardian's row at message time —
 * nothing is copied onto the child.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('guardian_id', 26)->nullable();
            $table->string('relationship', 16)->nullable();
            $table->index('guardian_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['guardian_id']);
            $table->dropColumn(['guardian_id', 'relationship']);
        });
    }
};
