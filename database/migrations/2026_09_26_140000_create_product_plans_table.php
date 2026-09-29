<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('version', 32);
            $table->string('name');
            $table->json('entitlement_defaults');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'code', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_plans');
    }
};
