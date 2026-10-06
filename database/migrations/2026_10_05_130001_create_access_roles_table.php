<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The access matrix (docs/DECISOES.md, realinhamento L9): roles with a grid of
 * permissions, and exceptions per person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name');
            $table->string('base', 20);
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('access_role_id')->nullable()->after('role')->constrained('access_roles')->nullOnDelete();
            $table->json('permission_overrides')->nullable()->after('access_role_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_role_id');
            $table->dropColumn('permission_overrides');
        });

        Schema::dropIfExists('access_roles');
    }
};
