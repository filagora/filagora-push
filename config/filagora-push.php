<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed push services
    |--------------------------------------------------------------------------
    |
    | A subscription's endpoint is called when a push is sent, so it must belong to a known
    | browser push service. Exact host names, or `*.example.com` for every sub-domain of one.
    | Only https on the default port is accepted.
    |
    */
    'allowed_hosts' => [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        '*.push.services.mozilla.com',
        '*.notify.windows.com',
        'web.push.apple.com',
        '*.push.apple.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stale subscriptions
    |--------------------------------------------------------------------------
    |
    | Days without use after which `filagora:push:prune-stale` (and core's retention run) removes a
    | subscription. Zero keeps them.
    |
    */
    'prune_days' => 180,

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | Which events become push alerts (each needs its package: forums for `mentions` and
    | `thread_updates`, messages for `messages`). `routes` name your thread and conversation pages
    | (they receive `thread` and `conversation`); without them an alert opens the site root, unless
    | those packages' email routes are set. `icon` and `badge` are image paths shown with an alert.
    |
    */
    'listen' => [
        'mentions' => true,
        'thread_updates' => true,
        'messages' => true,
    ],
    'routes' => [
        'thread' => null,
        'conversation' => null,
    ],
    'icon' => null,
    'badge' => null,

];
