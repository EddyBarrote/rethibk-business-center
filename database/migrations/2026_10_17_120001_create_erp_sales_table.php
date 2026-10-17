<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A copy of the ERP's sales (invoices, quotes) and catalogue items, line
     * by line, so agents can find past sales of a service (docs/DECISOES.md,
     * "Vendas parecidas"). Perfex only searches documents by number or client.
     */
    public function up(): void
    {
        Schema::create('erp_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('erp_id', 32);
            $table->string('number')->nullable();
            $table->string('customer')->nullable();
            $table->string('customer_id', 32)->nullable();
            $table->date('date')->nullable();
            $table->string('status', 32)->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('total', 15, 2)->nullable();
            $table->json('lines')->nullable();
            $table->text('note')->nullable();
            $table->mediumText('search_text')->nullable();
            $table->string('signature', 64);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'erp_id']);
            $table->index(['tenant_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_sales');
    }
};
