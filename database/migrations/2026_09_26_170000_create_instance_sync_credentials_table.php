<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instance_sync_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->unique()->constrained()->restrictOnDelete();
            $table->string('target_url');
            $table->string('key_id', 64);
            $table->text('secret');
            $table->string('previous_key_id', 64)->nullable();
            $table->text('previous_secret')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instance_sync_credentials');
    }
};
