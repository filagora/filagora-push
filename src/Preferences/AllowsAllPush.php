<?php

declare(strict_types=1);

namespace Filagora\Push\Preferences;

use Filagora\Push\Contracts\PushPreferences;
use Filagora\Push\Enums\PushCategory;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default: every kind of alert may be pushed to anyone who has a subscription.
 */
final class AllowsAllPush implements PushPreferences
{
    public function allows(Authenticatable $user, PushCategory $category): bool
    {
        return true;
    }
}
