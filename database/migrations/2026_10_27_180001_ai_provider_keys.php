<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The company's own keys for the AI providers (docs/DECISOES.md, "Chaves dos
 * provedores de IA na plataforma"), kept encrypted; the .env stays the
 * fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->text('key');
            $table->string('last_four', 8);
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_keys');
    }
};
