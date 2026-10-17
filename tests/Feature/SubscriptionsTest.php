<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Push\Enums\PushRefusal;
use Filagora\Push\Exceptions\PushRefused;
use Filagora\Push\Models\PushSubscription;
use Filagora\Push\Services\PushSubscriptions;
use Filagora\Push\Tests\Support\TestUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Minishlink\WebPush\ContentEncoding;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00')));

afterEach(fn () => Carbon::setTestNow());

const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

function subs(): PushSubscriptions
{
    return app(PushSubscriptions::class);
}

function refusalOf(callable $call): ?PushRefusal
{
    try {
        $call();
    } catch (PushRefused $e) {
        return $e->reason;
    }

    return null;
}

function pushStore(TestUser $user, string $endpoint = FCM, array $with = []): PushSubscription
{
    return subs()->store($user, $endpoint, $with['key'] ?? 'public-key', $with['auth'] ?? 'auth-token', $with['encoding'] ?? null, $with['agent'] ?? 'Firefox');
}

// -------------------------------------------------------------- the endpoint

it('accepts an endpoint on a known push service', function (string $endpoint) {
    expect(subs()->isAllowedEndpoint($endpoint))->toBeTrue();
})->with([
    'google' => ['https://fcm.googleapis.com/fcm/send/abc'],
    'mozilla' => ['https://updates.push.services.mozilla.com/wpush/v2/abc'],
    'mozilla sub-domain' => ['https://eu.push.services.mozilla.com/wpush/abc'],
    'windows' => ['https://db5p.notify.windows.com/?token=abc'],
    'apple' => ['https://web.push.apple.com/abc'],
    'apple sub-domain' => ['https://api.push.apple.com/3/device/abc'],
    'upper case' => ['HTTPS://FCM.GoogleAPIs.com/fcm/send/abc'],
    'explicit default port' => ['https://fcm.googleapis.com:443/fcm/send/abc'],
    'trailing dot' => ['https://fcm.googleapis.com./fcm/send/abc'],
]);

it('refuses an endpoint that could send the request somewhere else', function (string $endpoint) {
    expect(subs()->isAllowedEndpoint($endpoint))->toBeFalse();
})->with([
    'plain http' => ['http://fcm.googleapis.com/fcm/send/abc'],
    'localhost' => ['https://localhost/hook'],
    'loopback' => ['https://127.0.0.1/hook'],
    'metadata service' => ['https://169.254.169.254/latest/meta-data/'],
    'private network' => ['https://10.0.0.5/hook'],
    'user information' => ['https://fcm.googleapis.com@evil.test/x'],
    'user information with a password' => ['https://user:pass@fcm.googleapis.com/x'],
    'a backslash' => ['https://evil.test\\@fcm.googleapis.com/x'],
    'a fragment trick' => ['https://evil.test#@fcm.googleapis.com/x'],
    'an at sign in the path' => ['https://evil.test/@fcm.googleapis.com'],
    'a look-alike prefix' => ['https://evilfcm.googleapis.com/x'],
    'a look-alike suffix' => ['https://fcm.googleapis.com.evil.test/x'],
    'the wildcard apex' => ['https://push.apple.com/x'],
    'a wildcard look-alike' => ['https://notpush.apple.com/x'],
    'another port' => ['https://fcm.googleapis.com:8443/x'],
    'a space' => ['https://fcm.googleapis.com/a b'],
    'a newline' => ["https://fcm.googleapis.com/a\nb"],
    'a tab in the host' => ["https://fcm.googleapis.com\t.evil.test/x"],
    'a control character' => ["https://fcm.googleapis.com/a\x01b"],
    'no host' => ['https:///fcm.googleapis.com'],
    'another scheme' => ['javascript://fcm.googleapis.com/%0aalert(1)'],
    'not a url' => ['fcm.googleapis.com'],
    'empty' => [''],
]);

it('refuses an endpoint longer than the table holds', function () {
    $long = 'https://fcm.googleapis.com/'.str_repeat('a', 1020);

    expect(subs()->isAllowedEndpoint($long))->toBeFalse()
        ->and(subs()->isAllowedEndpoint('https://fcm.googleapis.com/'.str_repeat('a', 995)))->toBeTrue();
});

it('follows the configured hosts', function () {
    config(['filagora-push.allowed_hosts' => ['push.example.test', '*.edge.example.test', '  ', 'UPPER.example.test']]);

    expect(subs()->isAllowedEndpoint('https://push.example.test/x'))->toBeTrue()
        ->and(subs()->isAllowedEndpoint('https://a.edge.example.test/x'))->toBeTrue()
        ->and(subs()->isAllowedEndpoint('https://upper.example.test/x'))->toBeTrue()
        ->and(subs()->isAllowedEndpoint('https://fcm.googleapis.com/x'))->toBeFalse()
        ->and(subs()->isAllowedEndpoint('https://edge.example.test/x'))->toBeFalse();
});

// ------------------------------------------------------------------- storing

it('stores a subscription with the browser and the time', function () {
    $user = TestUser::make('Alice');

    $subscription = pushStore($user, with: ['agent' => str_repeat('u', 600)]);

    expect($subscription->subscribable_id)->toBe($user->id)->and($subscription->subscribable_type)->toBe($user->getMorphClass())
        ->and($subscription->endpoint)->toBe(FCM)->and($subscription->public_key)->toBe('public-key')->and($subscription->auth_token)->toBe('auth-token')
        ->and($subscription->content_encoding)->toBe(ContentEncoding::aes128gcm)
        ->and($subscription->user_agent)->toHaveLength(500)
        ->and($subscription->last_used_at->toDateTimeString())->toBe('2026-06-15 12:00:00')
        ->and($subscription->getTable())->toBe('filagora_push_subscriptions');
});

it('takes an older encoding when the browser says so, and leaves the browser unnamed if it is not sent', function () {
    $user = TestUser::make('Alice');

    $subscription = subs()->store($user, FCM, 'k', 'a', 'aesgcm');

    expect($subscription->content_encoding)->toBe(ContentEncoding::aesgcm)->and($subscription->user_agent)->toBeNull();
});

it('refreshes a subscription it already has instead of adding another', function () {
    $user = TestUser::make('Alice');
    pushStore($user);
    $this->travel(2)->hours();

    $again = pushStore($user, with: ['key' => 'new-key', 'agent' => 'Chrome']);

    expect(PushSubscription::count())->toBe(1)->and($again->public_key)->toBe('new-key')->and($again->user_agent)->toBe('Chrome')
        ->and($again->last_used_at->toDateTimeString())->toBe('2026-06-15 14:00:00');
});

it('moves a subscription to the person now using the browser', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    pushStore($alice);

    pushStore($bob);

    expect(PushSubscription::count())->toBe(1)->and(subs()->hasAny($alice))->toBeFalse()->and(subs()->hasAny($bob))->toBeTrue();
});

it('refuses what it cannot safely store, and stores nothing', function (string $endpoint, ?string $key, ?string $auth, ?string $encoding, PushRefusal $reason) {
    $user = TestUser::make('Alice');

    expect(refusalOf(fn () => subs()->store($user, $endpoint, $key, $auth, $encoding)))->toBe($reason)->and(PushSubscription::count())->toBe(0);
})->with([
    'an endpoint outside the push services' => ['https://evil.test/x', 'k', 'a', null, PushRefusal::INVALID_ENDPOINT],
    'no public key' => [FCM, null, 'a', null, PushRefusal::INVALID_SUBSCRIPTION],
    'an empty public key' => [FCM, '', 'a', null, PushRefusal::INVALID_SUBSCRIPTION],
    'no auth token' => [FCM, 'k', null, null, PushRefusal::INVALID_SUBSCRIPTION],
    'a key that is too long' => [FCM, str_repeat('k', 256), 'a', null, PushRefusal::INVALID_SUBSCRIPTION],
    'an auth token that is too long' => [FCM, 'k', str_repeat('a', 256), null, PushRefusal::INVALID_SUBSCRIPTION],
    'an unknown encoding' => [FCM, 'k', 'a', 'rot13', PushRefusal::INVALID_SUBSCRIPTION],
]);

it('allows keys of exactly 255 characters', function () {
    $subscription = subs()->store(TestUser::make('Alice'), FCM, str_repeat('k', 255), str_repeat('a', 255));

    expect($subscription->exists)->toBeTrue();
});

it('allows one row per endpoint at the database level', function () {
    $user = TestUser::make('Alice');
    $first = pushStore($user);

    $duplicate = (new PushSubscription(['endpoint' => FCM]))->forceFill(['subscribable_type' => $user->getMorphClass(), 'subscribable_id' => $user->id]);

    expect(fn () => $duplicate->save())->toThrow(QueryException::class, 'push_subscriptions_endpoint_unique')->and($first->exists)->toBeTrue();
});

// ----------------------------------------------------------------- resyncing

it('replaces an endpoint the browser rotated, but only one the person already has', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $old = 'https://fcm.googleapis.com/fcm/send/old';
    $new = 'https://fcm.googleapis.com/fcm/send/new';
    pushStore($alice, $old);
    pushStore($bob, 'https://fcm.googleapis.com/fcm/send/bobs');

    $subscription = subs()->resync($alice, $old, $new, 'k2', 'a2');

    expect($subscription->endpoint)->toBe($new)
        ->and(PushSubscription::pluck('endpoint')->sort()->values()->all())->toBe(['https://fcm.googleapis.com/fcm/send/bobs', $new]);
});

it('refuses a resync that names an endpoint the person does not have', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    pushStore($bob, 'https://fcm.googleapis.com/fcm/send/bobs');

    $attempt = fn (string $old) => refusalOf(fn () => subs()->resync($alice, $old, 'https://fcm.googleapis.com/fcm/send/planted', 'k', 'a'));

    expect($attempt('https://fcm.googleapis.com/fcm/send/bobs'))->toBe(PushRefusal::UNKNOWN_ENDPOINT)
        ->and($attempt('https://fcm.googleapis.com/fcm/send/never-seen'))->toBe(PushRefusal::UNKNOWN_ENDPOINT)
        ->and(PushSubscription::pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/bobs']);
});

it('refreshes in place when a resync names the same endpoint', function () {
    $alice = TestUser::make('Alice');
    pushStore($alice);

    subs()->resync($alice, FCM, FCM, 'new-key', 'new-auth');

    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::sole()->public_key)->toBe('new-key');
});

it('keeps the old subscription when the new endpoint is refused', function () {
    $alice = TestUser::make('Alice');
    pushStore($alice);

    expect(refusalOf(fn () => subs()->resync($alice, FCM, 'https://evil.test/x', 'k', 'a')))->toBe(PushRefusal::INVALID_ENDPOINT)
        ->and(PushSubscription::pluck('endpoint')->all())->toBe([FCM]);
});

// ----------------------------------------------------------------- the rest

it('removes only the person\'s own subscription', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    pushStore($alice);

    expect(subs()->remove($bob, FCM))->toBe(0)->and(PushSubscription::count())->toBe(1)
        ->and(subs()->remove($alice, FCM))->toBe(1)->and(PushSubscription::count())->toBe(0);
});

it('lists a person\'s subscriptions and no one else\'s', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    pushStore($alice, 'https://fcm.googleapis.com/fcm/send/1');
    pushStore($alice, 'https://fcm.googleapis.com/fcm/send/2');
    pushStore($bob, 'https://fcm.googleapis.com/fcm/send/3');
    (new PushSubscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/other-type']))->forceFill(['subscribable_type' => 'App\\Other', 'subscribable_id' => $alice->id])->save();

    expect(subs()->for($alice)->pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/1', 'https://fcm.googleapis.com/fcm/send/2'])
        ->and(subs()->hasAny($alice))->toBeTrue()->and(subs()->hasAny(TestUser::make('Nobody')))->toBeFalse();
});

it('uses the package model for the sending package', function () {
    expect(config('webpush.model'))->toBe(PushSubscription::class);
});

it('removes subscriptions unused for the days given, counting never-used ones from when they were made', function () {
    $user = TestUser::make('Alice');
    $old = pushStore($user, 'https://fcm.googleapis.com/fcm/send/old');
    $fresh = pushStore($user, 'https://fcm.googleapis.com/fcm/send/fresh');
    $neverUsedOld = pushStore($user, 'https://fcm.googleapis.com/fcm/send/never-old');
    $neverUsedNew = pushStore($user, 'https://fcm.googleapis.com/fcm/send/never-new');
    PushSubscription::whereKey($old->id)->toBase()->update(['last_used_at' => now()->subDays(200)]);
    PushSubscription::whereKey($fresh->id)->toBase()->update(['last_used_at' => now()->subDays(10)]);
    PushSubscription::whereKey($neverUsedOld->id)->toBase()->update(['last_used_at' => null, 'created_at' => now()->subDays(200)]);
    PushSubscription::whereKey($neverUsedNew->id)->toBase()->update(['last_used_at' => null, 'created_at' => now()->subDays(10)]);

    expect(subs()->pruneStale(180, dryRun: true))->toBe(2)->and(PushSubscription::count())->toBe(4)
        ->and(subs()->pruneStale(180))->toBe(2)
        ->and(PushSubscription::pluck('id')->sort()->values()->all())->toBe([$fresh->id, $neverUsedNew->id]);
});

it('never prunes anything used within a day, however the days are given', function () {
    $user = TestUser::make('Alice');
    $sub = pushStore($user);
    PushSubscription::whereKey($sub->id)->toBase()->update(['last_used_at' => now()->subHours(2)]);

    expect(subs()->pruneStale(0))->toBe(0)->and(subs()->pruneStale(-3))->toBe(0)->and(PushSubscription::count())->toBe(1);
});

it('prunes from the command, using the configured days or --days', function () {
    $sub = pushStore(TestUser::make('Alice'));
    PushSubscription::whereKey($sub->id)->toBase()->update(['last_used_at' => now()->subDays(40)]);

    $this->artisan('filagora:push:prune-stale')->expectsOutput('Pruned 0 stale push subscription(s).')->assertExitCode(0);
    $this->artisan('filagora:push:prune-stale', ['--days' => 30, '--dry-run' => true])->expectsOutput('Would prune 1 stale push subscription(s).')->assertExitCode(0);
    expect(PushSubscription::count())->toBe(1);

    config(['filagora-push.prune_days' => 30]);
    $this->artisan('filagora:push:prune-stale')->expectsOutput('Pruned 1 stale push subscription(s).')->assertExitCode(0);
    expect(PushSubscription::count())->toBe(0);
});

it('offers the same clean-up to core\'s retention run, and keeps everything when the window is zero', function () {
    $sub = pushStore(TestUser::make('Alice'));
    PushSubscription::whereKey($sub->id)->toBase()->update(['last_used_at' => now()->subDays(400)]);
    $rule = collect(app(RetentionRuleRegistry::class)->all())->first(fn ($rule) => $rule->key() === 'push.stale_subscriptions');

    config(['filagora-push.prune_days' => 0]);
    expect($rule->prune(false))->toBe(0)->and(PushSubscription::count())->toBe(1);

    config(['filagora-push.prune_days' => 180]);
    expect($rule->prune(true))->toBe(1)->and(PushSubscription::count())->toBe(1)->and($rule->prune(false))->toBe(1)->and(PushSubscription::count())->toBe(0);
});

it('exports a person\'s browsers without what would allow sending to them, and erases only theirs', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    pushStore($alice, 'https://updates.push.services.mozilla.com/wpush/v2/secret', ['agent' => 'Firefox 140']);
    pushStore($bob, 'https://fcm.googleapis.com/fcm/send/bobs');
    $contributor = app(PrivacyContributorRegistry::class)->all()['push'];

    $export = $contributor->export($alice);

    expect($export['subscriptions'])->toBe([[
        'push_service' => 'updates.push.services.mozilla.com',
        'user_agent' => 'Firefox 140',
        'last_used_at' => '2026-06-15T12:00:00+00:00',
        'created_at' => '2026-06-15T12:00:00+00:00',
    ]])->and(json_encode($export))->not->toContain('secret')->not->toContain('public-key')->not->toContain('auth-token');

    $contributor->erase($alice);

    expect(PushSubscription::pluck('subscribable_id')->all())->toBe([$bob->id]);
});
