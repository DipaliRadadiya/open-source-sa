<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * `site_mount_path` (DS-01). Null is the `/app` every existing container site
 * was created with, so the column is added empty and nothing is backfilled.
 */

it('adds and removes the site mount column', function () {
    $migration = require database_path('migrations/2026_10_07_170000_add_site_mount_path_to_applications.php');

    expect(Schema::hasColumn('applications', 'site_mount_path'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('applications', 'site_mount_path'))->toBeFalse();

    $migration->up();
    expect(Schema::hasColumn('applications', 'site_mount_path'))->toBeTrue();
});
