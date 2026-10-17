<?php

declare(strict_types=1);

namespace Filagora\Push\Services;

use Filagora\Push\Enums\PushRefusal;
use Filagora\Push\Exceptions\PushRefused;
use Filagora\Push\Models\PushSubscription;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Minishlink\WebPush\ContentEncoding;

/**
 * Browsers' push subscriptions, and the two checks that matter for them.
 *
 * The stored endpoint is called with the message when a push is sent, so it must belong to a known
 * browser push service (`allowed_hosts`): an endpoint on an internal host would make the server
 * request that host (server-side request forgery). `isAllowedEndpoint()` accepts only https on the
 * default port, a host that matches the list exactly or by a `*.` wildcard, and nothing that could
 * be read differently by the URL parser and the HTTP client (user information, `@`, backslashes,
 * whitespace or control characters).
 *
 * The service worker re-subscribes by itself when a browser rotates an endpoint, with no page and
 * no session token, so `resync()` makes it name the endpoint it is replacing: a browser only learns
 * its own endpoint from its own push manager, so someone riding a stolen or forged session cannot
 * plant their own endpoint on another person's account.
 */
class PushSubscriptions
{
    /** The longest endpoint the table holds. */
    public const ENDPOINT_MAX_LENGTH = 1024;

    public function isAllowedEndpoint(string $endpoint): bool
    {
        if ($endpoint === '' || strlen($endpoint) > self::ENDPOINT_MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f\\\\@]/', $endpoint) === 1) {
            return false;
        }

        $parts = parse_url($endpoint);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if ($host === '' || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }

        foreach ((array) config('filagora-push.allowed_hosts', []) as $pattern) {
            $pattern = strtolower(trim((string) $pattern));

            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1);

                if (str_ends_with($host, $suffix) && $host !== ltrim($suffix, '.')) {
                    return true;
                }

                continue;
            }

            if ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store (or refresh) this browser's subscription for the person. An endpoint already held by
     * someone else (a shared browser) moves to this person.
     *
     * @throws PushRefused
     */
    public function store(Authenticatable $user, string $endpoint, ?string $publicKey, ?string $authToken, ?string $contentEncoding = null, ?string $userAgent = null): PushSubscription
    {
        $encoding = $this->validated($endpoint, $publicKey, $authToken, $contentEncoding);

        try {
            return $this->save($user, $endpoint, $publicKey, $authToken, $encoding, $userAgent);
        } catch (UniqueConstraintViolationException) {
            return $this->save($user, $endpoint, $publicKey, $authToken, $encoding, $userAgent);
        }
    }

    /**
     * The browser rotated its endpoint: store the new one, and drop the old.
     *
     * @throws PushRefused when `$oldEndpoint` is not already on file for this person
     */
    public function resync(Authenticatable $user, string $oldEndpoint, string $endpoint, ?string $publicKey, ?string $authToken, ?string $contentEncoding = null, ?string $userAgent = null): PushSubscription
    {
        if (! $this->ownedBy($user)->where('endpoint', $oldEndpoint)->exists()) {
            throw new PushRefused(PushRefusal::UNKNOWN_ENDPOINT);
        }

        $subscription = $this->store($user, $endpoint, $publicKey, $authToken, $contentEncoding, $userAgent);

        if ($oldEndpoint !== $endpoint) {
            $this->remove($user, $oldEndpoint);
        }

        return $subscription;
    }

    /**
     * @return int How many subscriptions were removed (0 or 1).
     */
    public function remove(Authenticatable $user, string $endpoint): int
    {
        return $this->ownedBy($user)->where('endpoint', $endpoint)->delete();
    }

    /**
     * @return Collection<int, PushSubscription>
     */
    public function for(Authenticatable $user): Collection
    {
        /** @var Collection<int, PushSubscription> $subscriptions */
        $subscriptions = $this->ownedBy($user)->orderBy('id')->get();

        return $subscriptions;
    }

    public function hasAny(Authenticatable $user): bool
    {
        return $this->ownedBy($user)->exists();
    }

    /**
     * Remove subscriptions not used for `$days` days (never used ones count from when they were made).
     *
     * @return int How many were removed (counted, not removed, when `$dryRun`).
     */
    public function pruneStale(int $days, bool $dryRun = false): int
    {
        $cutoff = now()->subDays(max(1, $days));
        $query = $this->model()::query()->where(fn ($stale) => $stale
            ->where('last_used_at', '<', $cutoff)
            ->orWhere(fn ($unused) => $unused->whereNull('last_used_at')->where('created_at', '<', $cutoff)));

        $count = $query->count();

        if ($count > 0 && ! $dryRun) {
            $query->delete();
        }

        return $count;
    }

    private function save(Authenticatable $user, string $endpoint, ?string $publicKey, ?string $authToken, ContentEncoding $encoding, ?string $userAgent): PushSubscription
    {
        return DB::transaction(function () use ($user, $endpoint, $publicKey, $authToken, $encoding, $userAgent): PushSubscription {
            [$type, $id] = $this->owner($user);
            $subscription = $this->model()::query()->where('endpoint', $endpoint)->first();

            if ($subscription !== null && ((string) $subscription->getAttribute('subscribable_id') !== (string) $id || $subscription->getAttribute('subscribable_type') !== $type)) {
                $subscription->delete();
                $subscription = null;
            }

            $subscription ??= new ($this->model())(['endpoint' => $endpoint]);
            $subscription->forceFill([
                'subscribable_type' => $type,
                'subscribable_id' => $id,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => $encoding,
                'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 500, ''),
                'last_used_at' => now(),
            ])->save();

            return $subscription;
        });
    }

    /**
     * @throws PushRefused
     */
    private function validated(string $endpoint, ?string $publicKey, ?string $authToken, ?string $contentEncoding): ContentEncoding
    {
        if (! $this->isAllowedEndpoint($endpoint)) {
            throw new PushRefused(PushRefusal::INVALID_ENDPOINT);
        }

        foreach ([$publicKey, $authToken] as $key) {
            if ($key === null || $key === '' || strlen($key) > 255) {
                throw new PushRefused(PushRefusal::INVALID_SUBSCRIPTION);
            }
        }

        // The browser's own JSON leaves the encoding out; every current browser uses aes128gcm, and
        // the payload cannot be decrypted (and is silently dropped) without it.
        $encoding = ContentEncoding::tryFrom($contentEncoding ?? ContentEncoding::aes128gcm->value);

        return $encoding ?? throw new PushRefused(PushRefusal::INVALID_SUBSCRIPTION);
    }

    /**
     * @return Builder<PushSubscription>
     */
    private function ownedBy(Authenticatable $user): Builder
    {
        [$type, $id] = $this->owner($user);

        return $this->model()::query()->where('subscribable_type', $type)->where('subscribable_id', $id);
    }

    /**
     * @return array{0: string, 1: int|string}
     */
    private function owner(Authenticatable $user): array
    {
        /** @var int|string $id */
        $id = $user->getAuthIdentifier();

        return [$user instanceof Model ? $user->getMorphClass() : $user::class, $id];
    }

    /**
     * @return class-string<PushSubscription>
     */
    private function model(): string
    {
        /** @var class-string<PushSubscription> $model */
        $model = config('webpush.model', PushSubscription::class);

        return $model;
    }
}
