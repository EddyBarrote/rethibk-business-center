<?php

use App\Notifications\Channels\SearchableDatabaseChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The notifications centre's search (docs/DECISOES.md, "Centro de notificações"):
 * title, text and sender in plain lower case, filled for the ones already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->text('search')->nullable()->after('data');
        });

        DB::table('notifications')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true);
                DB::table('notifications')->where('id', $row->id)->update([
                    'search' => SearchableDatabaseChannel::searchText(is_array($data) ? $data : []),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('search');
        });
    }
};
