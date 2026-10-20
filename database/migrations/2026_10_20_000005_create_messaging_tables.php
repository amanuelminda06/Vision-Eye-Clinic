<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual-link messaging: templates, campaigns, group batches, prepared tasks,
 * drafts, the message library, saved audiences and public-site feedback.
 *
 * MessageTask carries the idempotency unique tuple
 * (source_type, source_id, template_key, patient_id) so repeated job runs can
 * never duplicate a prepared message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 32);                     // MessageTemplateKey
            $table->string('language', 8);                 // en | am
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->string('updated_by')->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->unique(['key', 'language']);
        });

        Schema::create('message_library_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->text('body');
            $table->string('language', 8);
            $table->string('category', 16)->default('REMINDER');
            $table->string('normalized_hash', 64);         // dup guard per language
            $table->boolean('is_favorite')->default(false);
            $table->unsignedInteger('use_count')->default(0);
            $table->dateTimeTz('last_used_at')->nullable();
            $table->string('created_by');
            $table->boolean('active')->default(true);      // hide = deactivate, never delete
            $table->string('pair_id')->nullable();         // links en/am versions
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->unique(['normalized_hash', 'language']);
            $table->index(['language', 'category']);
            $table->index(['is_favorite', 'active']);
            $table->index('use_count');
            $table->index('last_used_at');
            $table->index('pair_id');
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('template_key', 32)->nullable();
            $table->text('custom_body')->nullable();
            $table->string('language_mode', 16);           // PATIENT | FIXED
            $table->string('fixed_language', 8)->nullable();
            $table->json('filter_json');
            $table->string('status', 16)->default('DRAFT');
            $table->string('delivery_mode', 16)->default('INDIVIDUAL');
            $table->ulid('library_item_id')->nullable();
            $table->dateTimeTz('expires_at')->nullable();
            $table->string('created_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->foreign('library_item_id')->references('id')->on('message_library_items')->nullOnDelete();
        });

        Schema::create('campaign_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('campaign_id');
            $table->unsignedSmallInteger('batch_number');  // 1-based within campaign
            $table->unsignedSmallInteger('size');
            $table->string('status', 16)->default('PENDING'); // PENDING|SENT|SKIPPED
            $table->string('handled_by')->nullable();
            $table->dateTimeTz('handled_at')->nullable();
            $table->string('skip_reason')->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->unique(['campaign_id', 'batch_number']);
            $table->index('campaign_id');
            $table->index('status');
            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
        });

        Schema::create('message_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->string('template_key', 32);
            $table->string('source_type', 16);
            $table->string('source_id', 191);              // never null (stable period key)
            $table->ulid('campaign_id')->nullable();
            $table->string('language', 8);                 // rendered language
            $table->text('rendered_message');
            $table->dateTimeTz('due_at');
            $table->dateTimeTz('send_by')->nullable();     // deadline for sending
            $table->dateTimeTz('expires_at')->nullable();  // auto-expiry
            $table->string('status', 16)->default('PENDING');
            $table->string('blocked_reason')->nullable();   // i18n key, null unless BLOCKED
            $table->string('skip_reason')->nullable();
            $table->string('handled_by')->nullable();
            $table->dateTimeTz('handled_at')->nullable();
            $table->string('edited_by')->nullable();
            $table->dateTimeTz('edited_at')->nullable();
            $table->ulid('campaign_batch_id')->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();

            $table->unique(['source_type', 'source_id', 'template_key', 'patient_id']);
            $table->index(['status', 'due_at']);
            $table->index(['patient_id', 'created_at']);
            $table->index('campaign_id');
            $table->index(['status', 'expires_at']);
            $table->index('campaign_batch_id');
            $table->foreign('patient_id')->references('id')->on('patients');
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('campaign_batch_id')->references('id')->on('campaign_batches')->nullOnDelete();
        });

        Schema::create('message_drafts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('created_by');
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('language', 8)->default('en');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['created_by', 'updated_at']);
        });

        Schema::create('audiences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->json('filter_json');
            $table->string('created_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index('created_by');
        });

        Schema::create('feedback', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');                        // visitor display name
            $table->string('email')->nullable();           // CMS only, never public
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('text');
            $table->string('language', 8)->default('en');
            $table->boolean('show_on_website')->default(false);
            $table->string('ip_hash', 64)->nullable();     // sha-256, never raw IP
            $table->string('created_by');
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index(['show_on_website', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('audiences');
        Schema::dropIfExists('message_drafts');
        Schema::dropIfExists('message_tasks');
        Schema::dropIfExists('campaign_batches');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('message_library_items');
        Schema::dropIfExists('message_templates');
    }
};
