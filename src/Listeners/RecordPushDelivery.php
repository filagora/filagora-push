<?php

declare(strict_types=1);

namespace Filagora\Push\Listeners;

use Filagora\Push\Models\PushSubscription;
use NotificationChannels\WebPush\Events\NotificationSent;

/**
 * A push reached the push service: the subscription was used just now, so it is not stale.
 */
final class RecordPushDelivery
{
    public function handle(NotificationSent $event): void
    {
        if ($event->subscription instanceof PushSubscription) {
            $event->subscription->newQuery()->whereKey($event->subscription->getKey())->update(['last_used_at' => now()]);
        }
    }
}
