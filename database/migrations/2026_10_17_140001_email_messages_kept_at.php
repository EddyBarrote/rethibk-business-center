<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a person chose to keep an agent's draft («Guardar em rascunho»), saved
 * it or changed it: until then it is a proposal on the email and in the
 * conversation, not in the Rascunhos (docs/DECISOES.md, "Rascunho só quando a
 * pessoa escolhe"). The drafts a person already changed count as kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->timestamp('kept_at')->nullable()->after('edited_by_person_at');
        });

        DB::table('email_messages')->whereNotNull('edited_by_person_at')->update(['kept_at' => DB::raw('edited_by_person_at')]);
    }

    public function down(): void
    {
        Schema::table('email_messages', function (Blueprint $table) {
            $table->dropColumn('kept_at');
        });
    }
};
