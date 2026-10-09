<?php

use App\Enums\NotificationCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The notifications centre (docs/DECISOES.md, "Centro de notificações"): each
 * person's preferences per kind of notification, and the kind of the ones
 * already sent, kept in `notifications.type` so the centre filters by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('email_images_ask');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'type'], 'notifications_notifiable_type_index');
        });

        DB::table('notifications')->where('type', 'App\\Notifications\\PlatformNotice')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $data = json_decode((string) $row->data, true);
                DB::table('notifications')->where('id', $row->id)->update([
                    'type' => NotificationCategory::infer(is_array($data) ? $data : [])->value,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('notifications')->whereIn('type', array_column(NotificationCategory::cases(), 'value'))
            ->update(['type' => 'App\\Notifications\\PlatformNotice']);

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_notifiable_type_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};
