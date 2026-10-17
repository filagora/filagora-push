<?php

declare(strict_types=1);

namespace Filagora\Push\Contracts;

use Filagora\Push\Enums\PushCategory;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether a person wants this kind of alert as a push. A person with no subscription gets no push
 * whatever this says. The default allows every kind; bind your own to read the site's
 * notification settings.
 */
interface PushPreferences
{
    public function allows(Authenticatable $user, PushCategory $category): bool;
}
