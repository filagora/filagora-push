<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Browser push subscriptions, in the shape of the forum the package was extracted from: the table
 * of `laravel-notification-channels/webpush` (a subscription belongs to any model through
 * `subscribable_*`) plus the browser's user agent and when the subscription was last used. The
 * endpoint is ASCII so that it can carry a unique index. Use this migration instead of the one
 * that package publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('push_subscriptions'), function (Blueprint $table): void {
            $table->id();
            $table->string('subscribable_type');
            UserKey::column($table, 'subscribable_id');
            $table->string('endpoint', 1024)->charset('ascii')->collation('ascii_general_ci');
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('endpoint');
            $table->index(['subscribable_type', 'subscribable_id'], 'push_subscriptions_subscribable_morph_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('push_subscriptions'));
    }
};
