<?php

declare(strict_types=1);

namespace Filagora\Push\Console;

use Filagora\Push\Services\PushSubscriptions;
use Illuminate\Console\Command;

/**
 * Removes subscriptions of devices that have not been used in a long time. Endpoints a push
 * service reports as expired are already dropped when a push is sent; this clears the long tail of
 * abandoned devices.
 */
final class PruneStaleSubscriptionsCommand extends Command
{
    protected $signature = 'filagora:push:prune-stale {--days= : Days since last use (default from config)} {--dry-run : Report the count without deleting}';

    protected $description = 'Delete browser push subscriptions that have not been used in a long time.';

    public function handle(PushSubscriptions $subscriptions): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('filagora-push.prune_days', 180);
        $dryRun = (bool) $this->option('dry-run');

        $count = $subscriptions->pruneStale($days, $dryRun);

        $this->info(($dryRun ? 'Would prune ' : 'Pruned ')."{$count} stale push subscription(s).");

        return self::SUCCESS;
    }
}
