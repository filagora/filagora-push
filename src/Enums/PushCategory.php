<?php

declare(strict_types=1);

namespace Filagora\Push\Enums;

/**
 * The kinds of alert a person can choose to get as a push, for `PushPreferences` to answer about.
 */
enum PushCategory: string
{
    case MENTION = 'mention';
    case THREAD_UPDATE = 'thread_update';
    case PRIVATE_MESSAGE = 'private_message';
}
