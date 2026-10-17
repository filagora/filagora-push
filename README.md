# Filagora Push

Web push for Filagora: store people's browser subscriptions with the safety checks they need,
clean up the stale ones, include them in privacy exports, and send a push when someone is
mentioned, a followed thread has a new post, or a private message arrives. Built on
[`laravel-notification-channels/webpush`](https://github.com/laravel-notification-channels/webpush);
requires [`filagora/filagora-core`](https://github.com/filagora/filagora-core).

> In progress, unreleased. The package is at `0.1`: the API may change between `0.x` minor
> versions. There is no frontend: the service worker and the buttons are yours.

## Install

Not yet released.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
