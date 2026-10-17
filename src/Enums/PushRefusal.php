<?php

declare(strict_types=1);

namespace Filagora\Push\Enums;

/**
 * Why a push subscription was refused.
 */
enum PushRefusal: string
{
    case INVALID_ENDPOINT = 'invalid_endpoint';
    case INVALID_SUBSCRIPTION = 'invalid_subscription';
    case UNKNOWN_ENDPOINT = 'unknown_endpoint';

    public function message(): string
    {
        return __("filagora-push::push.refusals.{$this->value}");
    }
}
