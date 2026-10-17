<?php

declare(strict_types=1);

namespace Filagora\Push\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * One push alert: a title, a line of text and the address to open when it is tapped. Sent to the
 * browsers of one person through the web push channel, on the queue.
 */
final class WebPushAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly ?string $tag = null,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $message = (new WebPushMessage)->title($this->title)->body($this->body)->data(['url' => $this->url]);

        foreach (['icon', 'badge'] as $image) {
            $path = config("filagora-push.{$image}");

            if (is_string($path) && $path !== '') {
                $message->{$image}($path);
            }
        }

        return $this->tag !== null ? $message->tag($this->tag) : $message;
    }
}
