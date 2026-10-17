<?php

declare(strict_types=1);

namespace Filagora\Push\Tests\Switches;

class OnlyThreadUpdatesTestCase extends SwitchedTestCase
{
    protected function switches(): array
    {
        return ['mentions' => false, 'thread_updates' => true, 'messages' => false];
    }
}
