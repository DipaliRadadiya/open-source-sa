<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each archive was actually written.
 *
 * `backups` recorded only `backup_target_id`, and every after-the-fact reader
 * reached the destination through it — `$backup->target->storageDestination`.
 * A target's destination is editable, so repointing it moved *history*: every
 * older backup started resolving to a bucket its archive was never in.
 *
 * Concretely, before this column:
 *
 *  - download answered `download_missing` for every archive written before the
 *    change, because `exists()` was asked of the wrong bucket;
 *  - delete removed the row while failing to remove the object, leaving it
 *    orphaned in the old destination with nothing pointing at it;
 *  - retention pruning did the same, unattended, on every subsequent run;
 *  - the list showed the *current* destination's name against every historical
 *    row — a confident wrong answer about where a backup lives.
 *
 * The key was already recorded on the row ({@see UploadArtifact}) precisely so
 * nothing recomputes it. The destination is the other half of an address and
 * was left to be recomputed, which is the whole bug.
 *
 * Backfilled from the target, which is correct for every panel whose target has
 * not been repointed yet and is the best available answer for one that has —
 * there is no record of the old destination to recover.
 *
 * `restrictOnDelete`, matching `backup_targets`: a destination that still holds
 * archives cannot be deleted out from under them. The alternative — nulling the
 * column — would leave rows claiming an archive at an address nothing can
 * resolve, which is the state this migration exists to remove.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->foreignId('storage_destination_id')
                ->nullable()
                ->after('backup_target_id')
                ->constrained()
                ->restrictOnDelete();
        });

        // Nullable and backfilled rather than added non-null: a row whose
        // target or destination has already gone has no answer, and refusing
        // to migrate over it would strand the panel mid-upgrade.
        DB::table('backups')->whereNull('storage_destination_id')->update([
            'storage_destination_id' => DB::raw(
                '(SELECT storage_destination_id FROM backup_targets WHERE backup_targets.id = backups.backup_target_id)'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropForeign(['storage_destination_id']);
            $table->dropColumn('storage_destination_id');
        });
    }
};
