# Filagora Push

Web push for Filagora: store people's browser subscriptions with the safety checks they need,
clean up the stale ones, include them in privacy exports, and send a push when someone is
mentioned, a followed thread has a new post, or a private message arrives. Built on
[`laravel-notification-channels/webpush`](https://github.com/laravel-notification-channels/webpush);
requires [`filagora/filagora-core`](https://github.com/filagora/filagora-core).

> The package is at `0.1`: the API may change between `0.x` minor versions. There is no
> frontend: the service worker and the subscribe button are yours.

## What it does

- **Subscriptions.** `PushSubscriptions::store($user, $endpoint, $publicKey, $authToken, $encoding, $userAgent)`
  keeps this browser's subscription for a person (refreshing it if it is already there, moving it if
  someone else was using the browser). `resync()` is for the service worker's
  `pushsubscriptionchange`, which has no page and no session token: it only accepts a request that
  names the endpoint it is replacing, and only if that endpoint is already on file for the person, so
  a forged request cannot plant an endpoint on someone else's account. `remove()`, `for()` and
  `hasAny()` do what they say. A refusal is a `PushRefused` with a `PushRefusal` reason.
- **The endpoint is checked.** It is called when a push is sent, so it must belong to a known browser
  push service (`allowed_hosts`) or the server could be made to request an internal address. Only
  https on the default port is accepted, the host must match the list exactly or by `*.` wildcard,
  and anything a URL parser and an HTTP client could read differently (user information, `@`,
  backslashes, whitespace, control characters, over 1024 characters) is refused.
- **Sending.** `PushNotifier::send($user, PushCategory::MENTION, $title, $body, $url, $tag)` pushes to
  all of the person's browsers if they have a subscription and `PushPreferences` allows this kind of
  alert (the default allows all; bind your own to read the site's notification settings). It works
  for any user model, with no trait on it. The push goes out on the queue; browsers whose
  subscription the push service says has expired are dropped, and delivery moves the subscription's
  `last_used_at` forward.
- **Alerts for the other packages.** With `filagora-forums` installed, mentions and new posts in
  followed threads become push alerts; with `filagora-messages`, so do new private messages. They
  listen to the events those packages already fire, so those packages know nothing about push.
  Switch each off under `listen` in the config, and name your thread and conversation routes under
  `routes` (otherwise the alert opens their email routes, or the site root).
- **Clean-up and privacy.** `php artisan filagora:push:prune-stale` (schedule it) removes subscriptions
  unused for `prune_days` (180) and core's retention run does the same. The privacy export lists the
  push service, user agent and dates of a person's browsers, but not the endpoint or keys (they are
  what allows sending), and erasure removes them all.
- **Table** (`push_subscriptions`, prefixed `filagora_` by default) matches an existing site's table
  index for index, so `php artisan filagora:adopt` accepts it. Use this package's migration instead of
  the one the sending package publishes.

## Install

```bash
composer require filagora/filagora-push
php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag=config
php artisan webpush:vapid     # writes VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY to .env
php artisan migrate
```

The web push client needs your VAPID keys and a contact (`VAPID_SUBJECT`). On the page, subscribe with
`registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey })` and post the
result to a route of yours that calls `PushSubscriptions::store()`; in the service worker, show the
message and open `data.url` on click, and post to a route that calls `resync()` on
`pushsubscriptionchange`.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
