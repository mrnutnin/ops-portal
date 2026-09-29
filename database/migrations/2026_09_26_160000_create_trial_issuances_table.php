<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_issuances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('instance_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('product_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            // Only ordinary issuances claim the customer/product slot; approved exceptions keep NULL.
            $table->string('standard_claim', 64)->nullable()->unique();
            $table->boolean('is_exception')->default(false);
            $table->boolean('production_addon')->default(false);
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['customer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_issuances');
    }
};
