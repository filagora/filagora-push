<?php

declare(strict_types=1);

namespace Filagora\Push\Services;

use Filagora\Push\Contracts\PushPreferences;
use Filagora\Push\Enums\PushCategory;
use Filagora\Push\Notifications\WebPushAlert;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification;

/**
 * Sends a push alert to a person's browsers: only if they have at least one subscription and
 * `PushPreferences` allows this kind of alert. Works for any user model, with no trait needed on
 * it. Browsers whose subscription the push service reports as expired are removed when the push is
 * sent, and `last_used_at` moves forward when it is delivered.
 */
class PushNotifier
{
    public function __construct(private readonly PushSubscriptions $subscriptions, private readonly PushPreferences $preferences) {}

    /**
     * @return int How many browsers it was sent to (0 when the person has none or does not want it).
     */
    public function send(Authenticatable $user, PushCategory $category, string $title, string $body, string $url, ?string $tag = null): int
    {
        if (! $this->preferences->allows($user, $category)) {
            return 0;
        }

        $subscriptions = $this->subscriptions->for($user);

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        Notification::route('WebPush', $subscriptions)->notify(new WebPushAlert($title, $body, $url, $tag));

        return $subscriptions->count();
    }
}
