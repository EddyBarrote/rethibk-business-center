<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Section 5.8.
     */
    public function up(): void
    {
        Schema::create('erp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('transport', ['web', 'local']);
            $table->string('base_url')->nullable();
            $table->enum('auth_type', ['token', 'oauth'])->default('token');
            $table->text('credentials')->nullable();
            $table->enum('status', ['untested', 'ok', 'error', 'disabled'])->default('untested');
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_connections');
    }
};
