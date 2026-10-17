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
use Illuminate\Support\Facades\Notification;

function threadOnlySubscribed(string $name): TestUser
{
    $user = TestUser::make($name);
    app(PushSubscriptions::class)->store($user, 'https://fcm.googleapis.com/fcm/send/'.strtolower($name), 'k', 'a');

    return $user;
}

function threadOnlyPost(): Post
{
    $author = TestUser::make('Author');
    $group = ForumGroup::query()->firstOrCreate(['slug' => 'main'], ['name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'F', 'slug' => 'f']);
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $author->id, 'title' => 'T', 'slug' => 't']);

    return Post::create(['thread_id' => $thread->id, 'author_id' => $author->id, 'body' => 'Hi'])->refresh();
}

function threadOnlyMessage(): PrivateMessage
{
    $sender = TestUser::make('Sender');
    $conversation = PrivateConversation::create(['created_by_id' => $sender->id, 'subject' => 'Hi']);

    return PrivateMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $sender->id, 'body' => 'Hello'])->refresh();
}

it('pushes only the alerts left on', function () {
    Notification::fake();
    $bob = threadOnlySubscribed('Bob');
    $post = threadOnlyPost();
    $message = threadOnlyMessage();

    UsersMentioned::dispatch($post, [$bob]);
    MessageReceived::dispatch($message->conversation, $message, [$bob]);
    Notification::assertSentOnDemandTimes(WebPushAlert::class, 0);

    SubscribersToNotify::dispatch($post, $post->thread, [$bob]);
    Notification::assertSentOnDemandTimes(WebPushAlert::class, 1);
});
