<?php

declare(strict_types=1);

namespace Filagora\Push\Tests\Switches;

class OnlyMentionsAndMessagesTestCase extends SwitchedTestCase
{
    protected function switches(): array
    {
        return ['mentions' => true, 'thread_updates' => false, 'messages' => true];
    }
}
