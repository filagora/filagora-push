<?php

declare(strict_types=1);

namespace Filagora\Push\Exceptions;

use Filagora\Push\Enums\PushRefusal;
use RuntimeException;

/**
 * A push subscription that cannot be stored. The message is safe to show the user.
 */
final class PushRefused extends RuntimeException
{
    public function __construct(public readonly PushRefusal $reason)
    {
        parent::__construct($reason->message());
    }
}
