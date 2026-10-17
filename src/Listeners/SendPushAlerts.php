<?php

declare(strict_types=1);

namespace Filagora\Push\Listeners;

use Filagora\Core\Support\UserModel;
use Filagora\Forums\Events\SubscribersToNotify;
use Filagora\Forums\Events\UsersMentioned;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\Thread;
use Filagora\Messages\Events\MessageReceived;
use Filagora\Push\Enums\PushCategory;
use Filagora\Push\Services\PushNotifier;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Turns the events the forums and messages packages already fire into push alerts: someone was
 * mentioned, a followed thread has a new post, a private message arrived. Each is registered only
 * when its package is installed and its `listen.*` switch is on. The alert opens the named route of
 * your thread or conversation page (`routes.thread`, `routes.conversation`, falling back to the
 * route those packages use for their emails), or the site root when there is none.
 */
final class SendPushAlerts
{
    public function __construct(private readonly PushNotifier $notifier) {}

    public function mentioned(UsersMentioned $event): void
    {
        $post = $event->post;
        $thread = $post->thread;
        $url = $thread instanceof Thread ? $this->threadUrl($thread, $post) : url('/');

        foreach ($event->users as $user) {
            $this->notifier->send(
                $user,
                PushCategory::MENTION,
                __('filagora-push::push.alerts.mention', ['name' => $this->nameOf($post->author)]),
                $this->excerpt((string) $post->body),
                $url,
                'mention-'.$post->getKey(),
            );
        }
    }

    public function threadUpdated(SubscribersToNotify $event): void
    {
        $url = $this->threadUrl($event->thread, $event->post);

        foreach ($event->recipients as $user) {
            $this->notifier->send(
                $user,
                PushCategory::THREAD_UPDATE,
                __('filagora-push::push.alerts.thread_update', ['thread' => $event->thread->title]),
                $this->nameOf($event->post->author).': '.$this->excerpt((string) $event->post->body),
                $url,
                'thread-'.$event->thread->getKey(),
            );
        }
    }

    public function messageReceived(MessageReceived $event): void
    {
        $name = config('filagora-push.routes.conversation') ?? config('filagora-messages.mail.routes.conversation');
        $url = is_string($name) && $name !== '' && Route::has($name) ? route($name, ['conversation' => $event->conversation]) : url('/');

        foreach ($event->recipients as $user) {
            $this->notifier->send(
                $user,
                PushCategory::PRIVATE_MESSAGE,
                __('filagora-push::push.alerts.private_message', ['name' => $this->nameOf($event->message->sender)]),
                $this->excerpt((string) $event->message->body),
                $url,
                'conversation-'.$event->conversation->getKey(),
            );
        }
    }

    private function threadUrl(Thread $thread, Post $post): string
    {
        $name = config('filagora-push.routes.thread') ?? config('filagora-forums.mentions.routes.thread') ?? config('filagora-forums.subscriptions.routes.thread');

        return is_string($name) && $name !== '' && Route::has($name)
            ? route($name, ['thread' => $thread]).'#post-'.$post->getKey()
            : url('/');
    }

    private function nameOf(?Model $person): string
    {
        $name = $person instanceof Authenticatable ? $person->getAttribute(UserModel::titleAttribute()) : null;

        return is_string($name) && $name !== '' ? $name : __('filagora-push::push.alerts.someone');
    }

    private function excerpt(string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($text))), 120);
    }
}
