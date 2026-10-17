<?php

declare(strict_types=1);

use Filagora\Push\Contracts\PushPreferences;
use Filagora\Push\Enums\PushCategory;
use Filagora\Push\Models\PushSubscription;
use Filagora\Push\Notifications\WebPushAlert;
use Filagora\Push\Services\PushNotifier;
use Filagora\Push\Services\PushSubscriptions;
use Filagora\Push\Tests\Support\TestUser;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00')));

afterEach(fn () => Carbon::setTestNow());

function pushedTo(TestUser $user, string $path = 'one'): PushSubscription
{
    return app(PushSubscriptions::class)->store($user, "https://fcm.googleapis.com/fcm/send/{$path}", 'public-key', 'auth-token', null, 'Firefox');
}

function notifier(): PushNotifier
{
    app()->forgetInstance(PushNotifier::class);

    return app(PushNotifier::class);
}

it('sends an alert to every browser the person has subscribed', function () {
    Notification::fake();
    $alice = TestUser::make('Alice');
    pushedTo($alice, 'one');
    pushedTo($alice, 'two');
    pushedTo(TestUser::make('Bob'), 'bobs');

    $sent = notifier()->send($alice, PushCategory::MENTION, 'Title', 'Body', 'https://example.test/t', 'tag-1');

    expect($sent)->toBe(2);
    Notification::assertSentOnDemand(WebPushAlert::class, function (WebPushAlert $alert, array $channels, AnonymousNotifiable $notifiable) {
        return $alert->title === 'Title' && $alert->body === 'Body' && $alert->url === 'https://example.test/t' && $alert->tag === 'tag-1'
            && $channels === [WebPushChannel::class]
            && collect($notifiable->routeNotificationFor('WebPush'))->pluck('endpoint')->all() === ['https://fcm.googleapis.com/fcm/send/one', 'https://fcm.googleapis.com/fcm/send/two'];
    });
});

it('sends nothing to someone with no subscription', function () {
    Notification::fake();

    expect(notifier()->send(TestUser::make('Alice'), PushCategory::MENTION, 'T', 'B', '/'))->toBe(0);
    Notification::assertNothingSent();
});

it('asks the preferences about the kind of alert, and sends nothing when they say no', function () {
    Notification::fake();
    $alice = TestUser::make('Alice');
    pushedTo($alice);
    $asked = [];
    app()->instance(PushPreferences::class, new class($asked) implements PushPreferences
    {
        public function __construct(public array &$asked) {}

        public function allows(Authenticatable $user, PushCategory $category): bool
        {
            $this->asked[] = [$user->getAuthIdentifier(), $category];

            return $category !== PushCategory::THREAD_UPDATE;
        }
    });

    expect(notifier()->send($alice, PushCategory::THREAD_UPDATE, 'T', 'B', '/'))->toBe(0)
        ->and(notifier()->send($alice, PushCategory::MENTION, 'T', 'B', '/'))->toBe(1)
        ->and($asked)->toBe([[$alice->id, PushCategory::THREAD_UPDATE], [$alice->id, PushCategory::MENTION]]);
});

it('builds the message the browser shows, with the address to open and the configured images', function () {
    config(['filagora-push.icon' => '/img/icon.png', 'filagora-push.badge' => '/img/badge.png']);
    $alert = new WebPushAlert('Hello', 'World', 'https://example.test/x', 'tag-9');

    $message = $alert->toWebPush(new AnonymousNotifiable, $alert)->toArray();

    expect($message['title'])->toBe('Hello')->and($message['body'])->toBe('World')->and($message['data'])->toBe(['url' => 'https://example.test/x'])
        ->and($message['tag'])->toBe('tag-9')->and($message['icon'])->toBe('/img/icon.png')->and($message['badge'])->toBe('/img/badge.png')
        ->and($alert)->toBeInstanceOf(ShouldQueue::class);
});

it('leaves out the images and the tag when there are none', function () {
    $alert = new WebPushAlert('Hello', 'World', '/');

    $message = $alert->toWebPush(new AnonymousNotifiable, $alert)->toArray();

    expect($message)->not->toHaveKeys(['icon', 'badge', 'tag']);
});

// ------------------------------------------------- through the real channel

/**
 * Run the real channel against a push client that answers with the given status codes.
 *
 * @param  list<int>  $statuses  One per subscription, in order.
 */
function throughChannel(array $statuses): void
{
    $client = Mockery::mock(WebPush::class);
    $client->shouldReceive('queueNotification')->andReturnNull();
    $client->shouldReceive('flush')->andReturnUsing(function () use ($statuses) {
        foreach ($statuses as $index => $status) {
            yield new MessageSentReport(new Request('POST', "https://fcm.googleapis.com/fcm/send/{$index}"), new Response($status), $status < 300, 'reason');
        }
    });
    app()->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => $client);
}

it('records the delivery on the subscription', function () {
    $alice = TestUser::make('Alice');
    $subscription = pushedTo($alice, '0');
    $this->travel(3)->days();
    throughChannel([201]);

    notifier()->send($alice, PushCategory::MENTION, 'T', 'B', '/');

    expect($subscription->refresh()->last_used_at->toDateTimeString())->toBe('2026-06-18 12:00:00');
});

it('drops a subscription the push service says has expired, and keeps the others', function () {
    $alice = TestUser::make('Alice');
    $gone = pushedTo($alice, '0');
    $kept = pushedTo($alice, '1');
    $this->travel(3)->days();
    throughChannel([410, 201]);

    notifier()->send($alice, PushCategory::MENTION, 'T', 'B', '/');

    expect(PushSubscription::pluck('id')->all())->toBe([$kept->id])
        ->and($kept->refresh()->last_used_at->toDateTimeString())->toBe('2026-06-18 12:00:00')
        ->and($gone->exists)->toBeTrue();
});

it('does not touch last use when a push fails for another reason', function () {
    $alice = TestUser::make('Alice');
    $subscription = pushedTo($alice, '0');
    $this->travel(3)->days();
    throughChannel([500]);

    notifier()->send($alice, PushCategory::MENTION, 'T', 'B', '/');

    expect($subscription->refresh()->last_used_at->toDateTimeString())->toBe('2026-06-15 12:00:00')->and(PushSubscription::count())->toBe(1);
});
