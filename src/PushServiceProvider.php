<?php

declare(strict_types=1);

namespace Filagora\Push;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Forums\Events\SubscribersToNotify;
use Filagora\Forums\Events\UsersMentioned;
use Filagora\Messages\Events\MessageReceived;
use Filagora\Push\Console\PruneStaleSubscriptionsCommand;
use Filagora\Push\Contracts\PushPreferences;
use Filagora\Push\Listeners\RecordPushDelivery;
use Filagora\Push\Listeners\SendPushAlerts;
use Filagora\Push\Models\PushSubscription;
use Filagora\Push\Preferences\AllowsAllPush;
use Filagora\Push\Privacy\PushDataContributor;
use Filagora\Push\Privacy\StaleSubscriptionsRetention;
use Filagora\Push\Services\PushNotifier;
use Filagora\Push\Services\PushSubscriptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\WebPush\Events\NotificationSent;
use NotificationChannels\WebPush\PushSubscription as VendorPushSubscription;

class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-push.php', 'filagora-push');

        $this->app->singleton(PushSubscriptions::class);
        $this->app->bindIf(PushPreferences::class, AllowsAllPush::class);
        $this->app->singleton(PushNotifier::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('push', 'Filagora Push', 'filagora/filagora-push', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('push', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        // The sending package's own model, unless the host chose one.
        if (config('webpush.model') === null || config('webpush.model') === VendorPushSubscription::class) {
            config(['webpush.model' => PushSubscription::class]);
        }

        $this->app->make(PrivacyContributorRegistry::class)->register(new PushDataContributor);
        $this->app->make(RetentionRuleRegistry::class)->register(new StaleSubscriptionsRetention);

        Event::listen(NotificationSent::class, RecordPushDelivery::class);
        $this->listenForAlerts();

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-push');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneStaleSubscriptionsCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-push.php' => config_path('filagora-push.php'),
            ], 'filagora-push-config');
        }
    }

    /**
     * Push alerts for the events of the packages that are installed.
     */
    private function listenForAlerts(): void
    {
        if (class_exists(UsersMentioned::class)) {
            config('filagora-push.listen.mentions', true) && Event::listen(UsersMentioned::class, [SendPushAlerts::class, 'mentioned']);
            config('filagora-push.listen.thread_updates', true) && Event::listen(SubscribersToNotify::class, [SendPushAlerts::class, 'threadUpdated']);
        }

        if (class_exists(MessageReceived::class)) {
            config('filagora-push.listen.messages', true) && Event::listen(MessageReceived::class, [SendPushAlerts::class, 'messageReceived']);
        }
    }
}
