<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('product_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('commercial_mode', 20);
            $table->string('status', 20);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('overrides')->nullable();
            $table->boolean('production_addon')->default(false);
            $table->boolean('cancel_at_period_end')->default(false);
            $table->unsignedInteger('source_revision')->default(1);
            $table->string('change_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_entitlements');
    }
};
