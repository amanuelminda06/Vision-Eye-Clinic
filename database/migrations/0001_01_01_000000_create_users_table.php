<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation: staff, users, settings, CMS content, audit log, blob storage.
 *
 * All ids are ULID strings (opaque, URL-safe) to mirror the legacy cuid() keys.
 * All timestamps are stored in UTC (app timezone is UTC) as DATETIME columns so
 * the schema stays portable between MySQL 8.0 and SQLite (tests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('full_name');
            $table->string('job_title');
            $table->boolean('is_doctor')->default(false);
            $table->string('specialty')->nullable();
            $table->string('phone')->nullable();          // E.164 when present
            $table->string('photo_url')->nullable();      // storage key, served via /uploads
            $table->boolean('show_on_website')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('annual_leave_days')->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['active', 'is_doctor']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();            // stored lowercase
            $table->string('password');                   // argon2id hash
            $table->string('role', 16);                   // OWNER | RECEPTION | STAFF
            $table->ulid('staff_id')->nullable()->unique();
            $table->boolean('active')->default(true);
            $table->boolean('must_change_password')->default(true);
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->dateTimeTz('locked_until')->nullable();
            $table->dateTimeTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index('created_at');
            $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->dateTimeTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->unsignedInteger('last_activity')->index();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->string('updated_by')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
        });

        Schema::create('site_content', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value_en');
            $table->text('value_am');
            $table->string('updated_by')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('user_id')->nullable();
            $table->string('entity');
            $table->string('entity_id');
            $table->string('action');
            $table->json('old_json')->nullable();
            $table->json('new_json')->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();

            $table->index(['entity', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['created_at']);
            $table->index(['entity', 'action', 'created_at']);
        });

        Schema::create('stored_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();               // path-traversal-safe key
            $table->string('mime_type');
            $table->addColumn('mediumblob', 'data');       // <= 2 MB uploads
            $table->dateTimeTz('created_at')->nullable()->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('site_content');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('staff');
    }
};
