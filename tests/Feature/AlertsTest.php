<?php

declare(strict_types=1);

use Filagora\Forums\Events\SubscribersToNotify;
use Filagora\Forums\Events\UsersMentioned;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Messages\Events\MessageReceived;
use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateMessage;
use Filagora\Push\Notifications\WebPushAlert;
use Filagora\Push\Services\PushSubscriptions;
use Filagora\Push\Tests\Support\TestUser;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => Notification::fake());

function subscribed(string $name): TestUser
{
    $user = TestUser::make($name);
    app(PushSubscriptions::class)->store($user, 'https://fcm.googleapis.com/fcm/send/'.strtolower($name), 'k', 'a');

    return $user;
}

function forumPost(string $body = 'Hello there', ?TestUser $author = null): Post
{
    $author ??= TestUser::make('Author');
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'F', 'slug' => 'f-'.uniqid()]);
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'Topic of the day', 'slug' => 't-'.uniqid()]);

    return Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => $body])->refresh();
}

function directMessage(string $body = 'Are you there?', ?TestUser $sender = null): PrivateMessage
{
    $sender ??= TestUser::make('Sender');
    $conversation = PrivateConversation::create(['created_by_id' => $sender->id, 'subject' => 'Hi']);

    return PrivateMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $sender->id, 'body' => $body])->refresh();
}

/**
 * @return list<WebPushAlert>
 */
function alertsTo(TestUser $user): array
{
    $alerts = [];

    Notification::assertSentOnDemand(WebPushAlert::class, function (WebPushAlert $alert, array $channels, AnonymousNotifiable $notifiable) use (&$alerts, $user) {
        $endpoints = collect($notifiable->routeNotificationFor('WebPush'))->pluck('endpoint')->all();

        if ($endpoints === ['https://fcm.googleapis.com/fcm/send/'.strtolower($user->name)]) {
            $alerts[] = $alert;
        }

        return true;
    });

    return $alerts;
}

// -------------------------------------------------------------- mentions

it('pushes to each person mentioned in a post', function () {
    $bob = subscribed('Bob');
    $carol = subscribed('Carol');
    $post = forumPost('<p>Hello   <b>@bob</b>,   have a look</p>', TestUser::make('Dave'));

    UsersMentioned::dispatch($post, [$bob, $carol]);

    $alert = alertsTo($bob)[0];
    expect($alert->title)->toBe('Dave mentioned you')->and($alert->body)->toBe('Hello @bob, have a look')->and($alert->url)->toBe(url('/'))
        ->and($alert->tag)->toBe('mention-'.$post->id)->and(alertsTo($carol))->toHaveCount(1);
});

it('says someone when the author is not known', function () {
    $bob = subscribed('Bob');
    $post = forumPost();
    $post->setRelation('author', null);

    UsersMentioned::dispatch($post, [$bob]);

    expect(alertsTo($bob)[0]->title)->toBe('Someone mentioned you');
});

it('skips people who have not subscribed a browser', function () {
    $bob = TestUser::make('Bob');

    UsersMentioned::dispatch(forumPost(), [$bob]);

    Notification::assertSentOnDemandTimes(WebPushAlert::class, 0);
});

it('shortens a long post to a line', function () {
    $bob = subscribed('Bob');

    UsersMentioned::dispatch(forumPost(str_repeat('word ', 100)), [$bob]);

    $body = alertsTo($bob)[0]->body;
    expect($body)->toStartWith('word word')->toEndWith('...')->and(mb_strlen($body))->toBeLessThanOrEqual(123)->toBeGreaterThan(110);
});

it('opens the thread page at the post when the host names a route', function () {
    Route::get('/t/{thread}', fn () => '')->name('threads.show');
    app('router')->getRoutes()->refreshNameLookups();
    config(['filagora-push.routes.thread' => 'threads.show']);
    $bob = subscribed('Bob');
    $post = forumPost();

    UsersMentioned::dispatch($post, [$bob]);

    expect(alertsTo($bob)[0]->url)->toBe(route('threads.show', $post->thread).'#post-'.$post->id);
});

it('falls back to the route the forums package uses in its emails', function () {
    Route::get('/m/{thread}', fn () => '')->name('mentions.thread');
    Route::get('/s/{thread}', fn () => '')->name('subs.thread');
    app('router')->getRoutes()->refreshNameLookups();
    $bob = subscribed('Bob');
    $post = forumPost();

    config(['filagora-forums.mentions.routes.thread' => 'mentions.thread', 'filagora-forums.subscriptions.routes.thread' => 'subs.thread']);
    UsersMentioned::dispatch($post, [$bob]);
    expect(alertsTo($bob)[0]->url)->toBe(route('mentions.thread', $post->thread).'#post-'.$post->id);

    Notification::fake();
    config(['filagora-forums.mentions.routes.thread' => null]);
    UsersMentioned::dispatch($post, [$bob]);
    expect(alertsTo($bob)[0]->url)->toBe(route('subs.thread', $post->thread).'#post-'.$post->id);
});

it('opens the site when the named route does not exist', function () {
    config(['filagora-push.routes.thread' => 'no.such.route']);
    $bob = subscribed('Bob');

    UsersMentioned::dispatch(forumPost(), [$bob]);

    expect(alertsTo($bob)[0]->url)->toBe(url('/'));
});

// --------------------------------------------------------- thread updates

it('pushes a new post in a followed thread to the followers who want it', function () {
    $bob = subscribed('Bob');
    $quiet = TestUser::make('Quiet');
    $post = forumPost('The newest reply', TestUser::make('Eve'));

    SubscribersToNotify::dispatch($post, $post->thread, [$bob, $quiet]);

    $alert = alertsTo($bob)[0];
    expect($alert->title)->toBe('New post in Topic of the day')->and($alert->body)->toBe('Eve: The newest reply')
        ->and($alert->tag)->toBe('thread-'.$post->thread_id);
    Notification::assertSentOnDemandTimes(WebPushAlert::class, 1);
});

// ---------------------------------------------------------- private messages

it('pushes a new private message to its recipients', function () {
    Route::get('/c/{conversation}', fn () => '')->name('inbox.show');
    app('router')->getRoutes()->refreshNameLookups();
    $bob = subscribed('Bob');
    $message = directMessage('Are <i>you</i> there?', TestUser::make('Sam'));

    config(['filagora-push.routes.conversation' => 'inbox.show']);
    MessageReceived::dispatch($message->conversation, $message, [$bob]);

    $alert = alertsTo($bob)[0];
    expect($alert->title)->toBe('New message from Sam')->and($alert->body)->toBe('Are you there?')
        ->and($alert->url)->toBe(route('inbox.show', $message->conversation))->and($alert->tag)->toBe('conversation-'.$message->conversation_id);
});

it('falls back to the route the messages package uses in its emails, then the site', function () {
    Route::get('/mail/{conversation}', fn () => '')->name('mail.conversation');
    app('router')->getRoutes()->refreshNameLookups();
    $bob = subscribed('Bob');
    $message = directMessage();

    config(['filagora-messages.mail.routes.conversation' => 'mail.conversation']);
    MessageReceived::dispatch($message->conversation, $message, [$bob]);
    expect(alertsTo($bob)[0]->url)->toBe(route('mail.conversation', $message->conversation));

    Notification::fake();
    config(['filagora-messages.mail.routes.conversation' => null]);
    MessageReceived::dispatch($message->conversation, $message, [$bob]);
    expect(alertsTo($bob)[0]->url)->toBe(url('/'));
});

// ----------------------------------------------------------------- switches

it('registers the alert listeners for the installed packages', function () {
    expect(Event::hasListeners(UsersMentioned::class))->toBeTrue()
        ->and(Event::hasListeners(SubscribersToNotify::class))->toBeTrue()
        ->and(Event::hasListeners(MessageReceived::class))->toBeTrue();
});
