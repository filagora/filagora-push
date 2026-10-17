<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The table must match the site it was extracted from, so an existing site can adopt it as it is.
 */

const PUSH_MIGRATION = '2026_10_17_000000_create_filagora_push_subscription_table';

function pushMigration(): object
{
    return require __DIR__.'/../../database/migrations/'.PUSH_MIGRATION.'.php';
}

afterEach(function () {
    Schema::dropIfExists('push_subscriptions');
    config(['filagora.table_prefix' => 'filagora_']);

    if (! Schema::hasTable(Tables::name('push_subscriptions'))) {
        pushMigration()->up();
    }
});

it('ships a snapshot of the table that matches the migration, column order included', function () {
    $snapshot = app(SchemaRegistry::class)->get('push');

    expect(array_keys($snapshot->tables))->toBe(['push_subscriptions'])
        ->and($snapshot->migrationNames())->toBe([PUSH_MIGRATION])
        ->and(TableShape::inspect('push_subscriptions')->differencesFrom($snapshot->tables['push_subscriptions'], compareColumnOrder: true))->toBe([]);
});

it('keeps the endpoint ASCII and unique, and the lookup by owner', function () {
    $shape = TableShape::inspect('push_subscriptions');

    expect($shape->columns['endpoint'])->toMatchArray(['type' => 'varchar(1024)', 'nullable' => false, 'collation' => 'ascii_general_ci'])
        ->and($shape->indexes['push_subscriptions_endpoint_unique']['unique'])->toBeTrue()
        ->and($shape->indexes['push_subscriptions_subscribable_morph_idx']['columns'])->toBe(['subscribable_type', 'subscribable_id'])
        ->and($shape->columns['user_agent']['type'])->toBe('varchar(512)')
        ->and($shape->columns['last_used_at']['nullable'])->toBeTrue()
        ->and($shape->foreignKeys)->toBe([]);
});

it('adopts the table of an existing site and records the migration', function () {
    pushMigration()->down();
    config(['filagora.table_prefix' => '']);
    pushMigration()->up();
    DB::table('migrations')->where('migration', PUSH_MIGRATION)->delete();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('push'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toBe([PUSH_MIGRATION]);

    $this->artisan('filagora:adopt', ['--package' => 'push'])->expectsOutputToContain('Recorded 1 migration(s) as run.')->assertExitCode(0);
});

it('refuses a table that lost its unique endpoint, and is already adopted on a site that ran the migration', function () {
    expect(app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('push'))->status)->toBe(AdoptionStatus::AlreadyAdopted);

    pushMigration()->down();
    config(['filagora.table_prefix' => '']);
    pushMigration()->up();
    Schema::table('push_subscriptions', fn ($table) => $table->dropUnique('push_subscriptions_endpoint_unique'));

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('push'));

    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('push_subscriptions: index push_subscriptions_endpoint_unique is missing');
});
