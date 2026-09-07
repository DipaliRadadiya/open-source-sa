<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Give `workers` the `slug` column on panels that were installed without it.
 *
 * The column was added on 2026-09-01 by editing `create_workers_table` in
 * place. Laravel records a migration by filename, so every panel that had
 * already run that file will never run it again — the edit is invisible to
 * them. Fresh installs got the column and worked; upgraded ones 500 on the
 * first worker anyone tries to create, with `table workers has no column named
 * slug`. Nothing in the test suite could see it, because tests always build the
 * schema from scratch.
 *
 * This migration is the missing half. It is a no-op where the create already
 * provided the column, so both install paths end up with the same schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs took it from the create migration. Guarded rather than
        // assumed: this file has to be correct on a database it did not build.
        if (Schema::hasColumn('workers', 'slug')) {
            return;
        }

        // Nullable first. The column is NOT NULL in the create migration and
        // ends that way here, but existing rows have no value yet and adding a
        // NOT NULL column without a default to a populated table fails outright.
        Schema::table('workers', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        $this->backfill();

        Schema::table('workers', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('workers', 'slug')) {
            return;
        }

        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    /**
     * Derive a slug for every worker that predates the column.
     *
     * The rule is deliberately copied out of `Worker::uniqueSlug()` rather than
     * called. A migration describes a schema change at one moment in history and
     * has to keep producing the same result years later; reaching into a model
     * makes it change silently whenever that model does, and the one thing worse
     * than a missing slug is a slug that no longer matches the systemd unit
     * named after it.
     */
    private function backfill(): void
    {
        $taken = [];

        $workers = DB::table('workers')
            ->leftJoin('applications', 'applications.id', '=', 'workers.application_id')
            ->orderBy('workers.id')
            ->get(['workers.id', 'workers.name', 'applications.slug as application_slug']);

        foreach ($workers as $worker) {
            $base = trim(
                Str::slug((string) ($worker->application_slug ?? '')).'-'.Str::slug((string) $worker->name),
                '-',
            ) ?: 'worker';

            // Uniqueness tracked here rather than re-queried per row: the rows
            // being compared against are the ones this loop is still writing.
            $slug = $base;
            $suffix = 2;

            while (isset($taken[$slug])) {
                $slug = $base.'-'.$suffix++;
            }

            $taken[$slug] = true;

            DB::table('workers')->where('id', $worker->id)->update(['slug' => $slug]);
        }
    }
};
