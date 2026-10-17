<?php

declare(strict_types=1);

namespace Filagora\Push\Models;

use Filagora\Core\Support\Tables;
use Illuminate\Support\Carbon;
use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * One browser's subscription to push messages for one person. The sending package's model, on
 * this package's table, with the browser's user agent and when the subscription was last used.
 *
 * @property int $id
 * @property string|null $user_agent
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PushSubscription extends BasePushSubscription
{
    public function __construct(array $attributes = [])
    {
        $this->mergeFillable(['user_agent', 'last_used_at']);

        parent::__construct($attributes);
    }

    public function getTable(): string
    {
        return Tables::name('push_subscriptions');
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'last_used_at' => 'datetime',
        ]);
    }
}
