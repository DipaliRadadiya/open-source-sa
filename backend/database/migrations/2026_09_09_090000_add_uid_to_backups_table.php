<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A name for a backup's archive that no other backup can ever take.
 *
 * The object key was `backups/{domain}/{date}/{backup_id}.tar.gz`, and
 * `backup_id` is an autoincrement that only means anything inside one panel's
 * database. Reinstall the panel, or restore its database from an older dump,
 * and the counting starts at 1 again — so the next backup writes to the key an
 * existing archive already occupies, and an S3 PUT overwrites. The old backup
 * is destroyed silently, at exactly the moment somebody reinstalling would
 * want it. Two panels sharing a destination and a prefix collide the same way.
 *
 * Three steps in this order, and the order is the whole point: adding a unique
 * index to a column full of nulls fails on MySQL/MariaDB. SQLite is the
 * default here and would let it through, which is precisely why it has to be
 * written out rather than left to chance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->uuid('uid')->nullable()->after('id');
        });

        // Rows taken before this shipped keep their existing archives, which
        // are still named after the id — nothing is moved or renamed. The uid
        // is filled in anyway so the column is never null and every later row
        // can rely on it. One statement per row rather than a bulk update
        // because each needs its own value.
        DB::table('backups')->whereNull('uid')->orderBy('id')
            ->each(fn (object $backup) => DB::table('backups')
                ->where('id', $backup->id)
                ->update(['uid' => (string) Str::uuid()]));

        Schema::table('backups', function (Blueprint $table): void {
            $table->unique('uid');
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });
    }
};
