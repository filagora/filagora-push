<?php

declare(strict_types=1);

namespace Filagora\Push\Privacy;

use Filagora\Core\Contracts\RetentionRule;
use Filagora\Push\Services\PushSubscriptions;

/**
 * Subscriptions unused for `prune_days` days (180 by default; zero keeps them) are removed. The same
 * clean-up as `filagora:push:prune-stale`, offered to core's retention run.
 */
final class StaleSubscriptionsRetention implements RetentionRule
{
    public function key(): string
    {
        return 'push.stale_subscriptions';
    }

    public function prune(bool $dryRun): int
    {
        $days = (int) config('filagora-push.prune_days', 180);

        return $days <= 0 ? 0 : app(PushSubscriptions::class)->pruneStale($days, $dryRun);
    }
}
