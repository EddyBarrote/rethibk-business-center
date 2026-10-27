<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own Google Calendar (docs/DECISOES.md, "Google Calendar por
 * pessoa"): the account they connected, with their consent, and the events
 * kept in step both ways. Tokens are encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20)->default('google');
            $table->string('account_email')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            // The calendar the platform writes to: its own, created in the person's account.
            $table->string('platform_calendar_id')->nullable();
            // Incremental reading of the person's primary calendar.
            $table->text('sync_token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'provider']);
        });

        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('calendar_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // "in": an event of the person's calendar; "out": a platform item written to Google.
            $table->string('direction', 3);
            $table->string('external_id');
            // For "out": the agenda item it mirrors (follow_up-12, task-7) and a hash of what was sent.
            $table->string('source_key')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->string('title')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('html_link', 1000)->nullable();
            $table->timestamps();

            $table->unique(['calendar_connection_id', 'direction', 'external_id']);
            $table->index(['tenant_id', 'user_id', 'starts_at']);
            $table->index(['calendar_connection_id', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
        Schema::dropIfExists('calendar_connections');
    }
};
