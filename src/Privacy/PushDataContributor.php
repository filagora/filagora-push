<?php

declare(strict_types=1);

namespace Filagora\Push\Privacy;

use Filagora\Core\Contracts\PrivacyContributor;
use Filagora\Push\Services\PushSubscriptions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The browsers a person subscribed to push on, for an export: which push service, the browser's
 * user agent and when. The endpoint itself and the keys are not exported, because they are what
 * allows sending to the browser. Erasure removes every subscription.
 */
final class PushDataContributor implements PrivacyContributor
{
    public function key(): string
    {
        return 'push';
    }

    public function export(Model $user): array
    {
        if (! $user instanceof Authenticatable) {
            return ['subscriptions' => []];
        }

        return [
            'subscriptions' => app(PushSubscriptions::class)->for($user)->map(fn ($subscription): array => [
                'push_service' => parse_url((string) $subscription->endpoint, PHP_URL_HOST) ?: null,
                'user_agent' => $subscription->user_agent,
                'last_used_at' => $subscription->last_used_at?->toIso8601String(),
                'created_at' => $subscription->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function erase(Model $user): void
    {
        if (! $user instanceof Authenticatable) {
            return;
        }

        $subscriptions = app(PushSubscriptions::class);

        foreach ($subscriptions->for($user) as $subscription) {
            $subscriptions->remove($user, $subscription->endpoint);
        }
    }
}
