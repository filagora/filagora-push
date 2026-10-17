<?php

declare(strict_types=1);

namespace Filagora\Push\Tests\Switches;

use Filagora\Push\Tests\TestCase;

/**
 * The alert listeners are registered when the application boots, so a switch has to be set before
 * that. Subclasses name which alerts to leave on.
 */
abstract class SwitchedTestCase extends TestCase
{
    /**
     * @return array{mentions: bool, thread_updates: bool, messages: bool}
     */
    abstract protected function switches(): array;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->switches() as $name => $on) {
            $app['config']->set("filagora-push.listen.{$name}", $on);
        }
    }
}
