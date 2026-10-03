<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_entitlements', function (Blueprint $table) {
            // MySQL DDL is not transactional; allow retry after a failed table creation.
            if (! Schema::hasColumn('instance_entitlements', 'paid_period_end')) {
                $table->timestamp('paid_period_end')->nullable();
            }
            if (! Schema::hasColumn('instance_entitlements', 'grace_days')) {
                $table->unsignedSmallInteger('grace_days')->default(0);
            }
        });
        Schema::create('instance_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->constrained()->restrictOnDelete();
            $table->foreignId('open_instance_id')->nullable()->unique()->constrained('instances')->restrictOnDelete();
            $table->string('kind', 20); // RENEWAL, GRACE (legacy enrollment), TRIAL (first payment)
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->dateTime('payment_due_at');
            $table->unsignedSmallInteger('grace_days');
            $table->unsignedInteger('expected_revision');
            $table->json('before_values');
            $table->decimal('agreed_amount', 14, 2);
            $table->decimal('received_amount', 14, 2)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('external_reference', 255)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('reason', 500);
            $table->string('difference_reason', 500)->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('evidence_name')->nullable();
            $table->string('evidence_mime', 100)->nullable();
            $table->unsignedInteger('evidence_size')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('applied_revision')->nullable();
            $table->json('after_values')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['instance_id', 'id']);
            $table->index(['status', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_renewals');
        Schema::table('instance_entitlements', fn (Blueprint $table) => $table->dropColumn(['paid_period_end', 'grace_days']));
    }
};
