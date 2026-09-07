<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The repair for a migration that was edited in place.
 *
 * `slug` was added to `workers` on 2026-09-01 by changing
 * create_workers_table rather than adding a new migration. Laravel records a
 * migration by filename, so panels that had already run that file never saw
 * the edit: fresh installs got the column, upgraded ones 500 on the first
 * worker anyone created.
 *
 * No test could catch it, and that is the part worth keeping. The suite always
 * builds the schema from scratch, where the edited create migration is simply
 * correct. The only way to see this class of bug is to reconstruct the *old*
 * schema and migrate it, which is what these tests do.
 */

/** The `workers` table as it stood before 2026-09-01 — no `slug`. */
function dropSlugColumn(): void
{
    Schema::table('workers', function (Blueprint $table) {
        $table->dropUnique(['slug']);
        $table->dropColumn('slug');
    });
}

function runSlugMigration(): void
{
    $migration = require database_path('migrations/2026_09_07_090000_add_slug_to_workers_table.php');
    $migration->up();
}

function makeApplication(string $slug): int
{
    $systemUser = DB::table('system_users')->insertGetId([
        'username' => 'w'.substr(md5($slug), 0, 6),
        'home_path' => '/home/'.$slug,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('applications')->insertGetId([
        'system_user_id' => $systemUser,
        'name' => $slug,
        'slug' => $slug,
        'domain' => $slug.'.test',
        'site_type' => 'git',
        'serving_profile' => 'php',
        'status' => 'active',
        'web_root' => '/',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertWorkerWithoutSlug(int $applicationId, string $name): int
{
    return DB::table('workers')->insertGetId([
        'application_id' => $applicationId,
        'name' => $name,
        'command' => 'php artisan queue:work',
        'kind' => 'queue',
        'processes' => 1,
        'stop_wait_seconds' => 30,
        'auto_restart' => true,
        'restart_on_deploy' => true,
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('adds the column a panel upgraded from before 2026-09-01 never received', function () {
    dropSlugColumn();

    expect(Schema::hasColumn('workers', 'slug'))->toBeFalse();

    runSlugMigration();

    expect(Schema::hasColumn('workers', 'slug'))->toBeTrue();
});

it('gives every existing worker the slug its systemd unit will be named after', function () {
    $application = makeApplication('shop');
    dropSlugColumn();

    $id = insertWorkerWithoutSlug($application, 'Email Queue');

    runSlugMigration();

    // Application slug plus worker name, exactly as Worker::uniqueSlug builds
    // it — the unit is `sv-worker-shop-email-queue@1.service`.
    expect(DB::table('workers')->where('id', $id)->value('slug'))->toBe('shop-email-queue');
});

it('keeps two workers apart when their names reduce to the same slug', function () {
    // Str::slug is lossy: "My Queue" and "my-queue" collapse to one string, and
    // the column is unique server-wide because a unit name is.
    $application = makeApplication('shop');
    dropSlugColumn();

    $first = insertWorkerWithoutSlug($application, 'My Queue');
    $second = insertWorkerWithoutSlug($application, 'my-queue');

    runSlugMigration();

    $slugs = DB::table('workers')->whereIn('id', [$first, $second])->pluck('slug');

    expect($slugs->unique())->toHaveCount(2)
        ->and($slugs)->toContain('shop-my-queue')
        ->and($slugs)->toContain('shop-my-queue-2');
});

it('separates workers of the same name on different applications', function () {
    $shop = makeApplication('shop');
    $blog = makeApplication('blog');
    dropSlugColumn();

    $one = insertWorkerWithoutSlug($shop, 'Queue');
    $two = insertWorkerWithoutSlug($blog, 'Queue');

    runSlugMigration();

    expect(DB::table('workers')->where('id', $one)->value('slug'))->toBe('shop-queue')
        ->and(DB::table('workers')->where('id', $two)->value('slug'))->toBe('blog-queue');
});

it('leaves a fresh install alone', function () {
    // The create migration already provided the column. Running this against
    // it must not fail, and must not touch the slugs already there.
    $application = makeApplication('shop');

    $id = DB::table('workers')->insertGetId([
        'application_id' => $application,
        'name' => 'Queue',
        'slug' => 'shop-queue',
        'command' => 'php artisan queue:work',
        'kind' => 'queue',
        'processes' => 1,
        'stop_wait_seconds' => 30,
        'auto_restart' => true,
        'restart_on_deploy' => true,
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runSlugMigration();

    expect(DB::table('workers')->where('id', $id)->value('slug'))->toBe('shop-queue');
});

it('ends with the column no longer accepting null, as the create migration has it', function () {
    // Both install paths must reach the same schema, or the next person to read
    // one of them is reading a lie about the other.
    $application = makeApplication('shop');
    dropSlugColumn();
    insertWorkerWithoutSlug($application, 'Queue');

    runSlugMigration();

    expect(fn () => DB::table('workers')->insert([
        'application_id' => $application,
        'name' => 'No Slug',
        'slug' => null,
        'command' => 'php artisan queue:work',
        'kind' => 'queue',
        'processes' => 1,
        'stop_wait_seconds' => 30,
        'auto_restart' => true,
        'restart_on_deploy' => true,
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces uniqueness on the repaired column', function () {
    $application = makeApplication('shop');
    dropSlugColumn();
    insertWorkerWithoutSlug($application, 'Queue');

    runSlugMigration();

    expect(fn () => DB::table('workers')->insert([
        'application_id' => $application,
        'name' => 'Another',
        'slug' => 'shop-queue',
        'command' => 'php artisan queue:work',
        'kind' => 'queue',
        'processes' => 1,
        'stop_wait_seconds' => 30,
        'auto_restart' => true,
        'restart_on_deploy' => true,
        'enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
