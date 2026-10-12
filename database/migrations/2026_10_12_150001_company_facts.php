<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memória da empresa (docs/DECISOES.md, 12.10.2026): one curated list of
 * facts every agent always knows, by section. Agents propose, a person
 * approves. The decisions already recorded become its "Decisões" section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('section', 20);
            $table->text('content');
            $table->foreignId('knowledge_domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('published');
            $table->foreignId('replaces_id')->nullable()->constrained('company_facts')->nullOnDelete();
            $table->foreignId('proposed_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('knowledge_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'section']);
        });

        DB::table('knowledge_items')
            ->where('type', 'decision')
            ->where('status', 'published')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'title', 'summary', 'content', 'created_at', 'updated_at'])
            ->each(fn (object $item) => DB::table('company_facts')->insert([
                'tenant_id' => $item->tenant_id,
                'section' => 'decisions',
                'content' => mb_substr(trim($item->title.': '.($item->summary ?: $item->content)), 0, 1000),
                'status' => 'published',
                'knowledge_item_id' => $item->id,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('company_facts');
    }
};
