<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a person wants to be asked before an email loads its external
 * images; by default they load (docs/DECISOES.md, "Email: imagens carregam sozinhas").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_images_ask')->default(false)->after('last_mailbox_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_images_ask');
        });
    }
};
