<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Martis in-app notifications table.
 *
 * Backed by Laravel's standard notifications shape so any
 * `Notification` class using the `database` channel can deliver into
 * Martis's bell dropdown without extra plumbing. The expected `data`
 * payload (Martis convention) is:
 *
 *   {
 *     "title": "Invoice paid",
 *     "message": "INV-2026-001 has been paid",
 *     "level": "success",            // info | success | warning | danger
 *     "icon": "check-circle",        // optional, defaults by level
 *     "action_url": "/martis/...",   // optional, click target
 *     "action_label": "View invoice" // optional, button label
 *   }
 *
 * Apps that already have Laravel notifications can keep using their
 * existing classes — the Martis UI renders any payload, falling back
 * to the notification's class name when `title` is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
