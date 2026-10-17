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

];
