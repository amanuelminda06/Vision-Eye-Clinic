<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service catalogue, price history, sales, sale lines and the gap-free
 * receipt counter.
 *
 * Money is NUMERIC(12,2) — never floats. Sale lines snapshot the service name
 * and price at sale time; confirmed sales are immutable (void only), so these
 * rows are never updated after confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');                       // historical display name
            $table->string('name_en')->default('');       // current English name
            $table->string('name_am')->nullable();        // Amharic name (optional)
            $table->string('category', 64);
            $table->decimal('current_price', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('is_public')->default(true);  // listed on the website
            $table->text('public_description_en')->nullable();
            $table->text('public_description_am')->nullable();
            $table->string('receipt_label_en')->nullable();
            $table->string('receipt_label_am')->nullable();
            $table->integer('display_order')->default(0);
            $table->string('image_url')->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index('category');
            $table->index('name');
        });

        Schema::create('service_price_history', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('service_id');
            $table->decimal('old_price', 12, 2);
            $table->decimal('new_price', 12, 2);
            $table->string('changed_by');
            $table->dateTimeTz('changed_at')->nullable()->useCurrent();

            $table->index(['service_id', 'changed_at']);
            $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('receipt_number', 32)->unique(); // e.g. 2026-000123
            $table->ulid('patient_id')->nullable();
            $table->ulid('doctor_id')->nullable();
            $table->dateTimeTz('sale_date');
            $table->string('status', 16)->default('DRAFT'); // DRAFT|CONFIRMED|VOIDED
            $table->string('payment_method', 16);
            $table->decimal('total', 12, 2);                // server-computed sum
            $table->string('void_requested_by')->nullable();
            $table->string('void_request_reason')->nullable();
            $table->string('voided_by')->nullable();
            $table->string('void_reason')->nullable();
            $table->dateTimeTz('voided_at')->nullable();
            $table->string('created_by');
            $table->dateTimeTz('confirmed_at')->nullable();
            $table->dateTimeTz('created_at')->nullable()->useCurrent();
            $table->dateTimeTz('updated_at')->nullable();

            $table->index('sale_date');
            $table->index(['status', 'sale_date']);
            $table->index('patient_id');
            $table->index('doctor_id');
            $table->index(['created_by', 'sale_date']);
            $table->index('payment_method');
            $table->index(['status', 'void_requested_by']);
            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
            $table->foreign('doctor_id')->references('id')->on('staff')->nullOnDelete();
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('sale_id');
            $table->ulid('service_id');
            $table->string('service_name_snapshot');
            $table->unsignedSmallInteger('qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);

            $table->index('sale_id');
            $table->index('service_id');
            $table->foreign('sale_id')->references('id')->on('sales');
            $table->foreign('service_id')->references('id')->on('services');
        });

        Schema::create('receipt_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary(); // clinic-local calendar year
            $table->unsignedInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_counters');
        Schema::dropIfExists('sale_lines');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('service_price_history');
        Schema::dropIfExists('services');
    }
};
