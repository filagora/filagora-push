# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Table `push_subscriptions` (matching the existing site's table, index for index; use this migration instead of the sending package's) and the `PushSubscription` model on it.
- `PushSubscriptions`: `store`, `resync` (the old endpoint must already be on file for the person), `remove`, `for`, `hasAny` and `pruneStale`; `isAllowedEndpoint` accepts only https on the default port to a host in `allowed_hosts`, and refuses anything a URL parser and an HTTP client could read differently. A refusal is a `PushRefused` with a `PushRefusal` reason.
- `PushNotifier` and `WebPushAlert`: push to a person's browsers if they have a subscription and `PushPreferences` (default `AllowsAllPush`) allows the kind of alert (`PushCategory`); works with any user model; expired subscriptions are dropped and delivery updates `last_used_at`.
- Alerts for mentions, new posts in followed threads (with `filagora-forums`) and private messages (with `filagora-messages`), each switchable under `listen`, with `routes` for the pages they open.
- `filagora:push:prune-stale` and the retention rule `push.stale_subscriptions` (180 days); a privacy contributor (export without endpoint or keys; erasure).
