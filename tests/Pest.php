<?php

declare(strict_types=1);

use Filagora\Push\Tests\Switches\OnlyMentionsAndMessagesTestCase;
use Filagora\Push\Tests\Switches\OnlyThreadUpdatesTestCase;
use Filagora\Push\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(OnlyThreadUpdatesTestCase::class)->use(RefreshDatabase::class)->in('Switches/OnlyThreadUpdates');
pest()->extend(OnlyMentionsAndMessagesTestCase::class)->use(RefreshDatabase::class)->in('Switches/OnlyMentionsAndMessages');
