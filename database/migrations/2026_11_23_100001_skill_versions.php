<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skill versions (docs/DECISOES.md, "Latência, taxa de erro e versões das
     * skills"): every change to what an agent reads in a skill (name, «quando
     * usar», instructions, files) keeps an immutable copy. Two tables, as the
     * skills themselves: the company's (with tenant_id) and the global ones.
     *
     * Each existing skill gets its version 1 from what it holds today.
     */
    public function up(): void
    {
        Schema::create('skill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('key');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->longText('instructions')->nullable();
            $table->json('files')->nullable();
            $table->unsignedInteger('restored_from')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['skill_id', 'number']);
        });

        Schema::create('platform_skill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('key');
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->longText('instructions')->nullable();
            $table->json('files')->nullable();
            $table->unsignedInteger('restored_from')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['platform_skill_id', 'number']);
        });

        $this->backfill();
    }

    /**
     * Version 1 of every skill that holds its own content: a company's own
     * skills (an activated global one holds none) and the global ones.
     */
    private function backfill(): void
    {
        DB::table('skills')->whereNull('platform_skill_id')->orderBy('id')->chunkById(100, function ($skills) {
            foreach ($skills as $skill) {
                $files = DB::table('skill_files')->where('skill_id', $skill->id)->orderBy('filename')->get(['filename', 'path', 'mime', 'size', 'content']);

                DB::table('skill_versions')->insert([
                    'tenant_id' => $skill->tenant_id,
                    'skill_id' => $skill->id,
                    'number' => 1,
                    'key' => $skill->key,
                    'name' => $skill->name,
                    'description' => $skill->description,
                    'instructions' => $skill->instructions,
                    'files' => json_encode($this->files($files), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    'created_by_user_id' => $skill->created_by_user_id,
                    'created_at' => $skill->updated_at ?? $skill->created_at ?? now(),
                ]);
            }
        });

        DB::table('platform_skills')->orderBy('id')->chunkById(100, function ($skills) {
            foreach ($skills as $skill) {
                $files = DB::table('platform_skill_files')->where('platform_skill_id', $skill->id)->orderBy('filename')->get(['filename', 'path', 'mime', 'size', 'content']);

                DB::table('platform_skill_versions')->insert([
                    'platform_skill_id' => $skill->id,
                    'number' => 1,
                    'key' => $skill->key,
                    'name' => $skill->name,
                    'description' => $skill->description,
                    'instructions' => $skill->instructions,
                    'files' => json_encode($this->files($files), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    'created_by_admin_id' => null,
                    'created_at' => $skill->updated_at ?? $skill->created_at ?? now(),
                ]);
            }
        });
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{filename: string, path: string, mime: string|null, size: int, content: string|null}>
     */
    private function files(iterable $rows): array
    {
        $files = [];

        foreach ($rows as $row) {
            $files[] = [
                'filename' => (string) $row->filename,
                'path' => (string) $row->path,
                'mime' => $row->mime,
                'size' => (int) $row->size,
                'content' => $row->content,
            ];
        }

        return $files;
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_skill_versions');
        Schema::dropIfExists('skill_versions');
    }
};
