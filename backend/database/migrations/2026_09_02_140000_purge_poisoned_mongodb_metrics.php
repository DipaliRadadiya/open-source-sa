<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Drop every MongoDB sample taken before the opcounter sum was fixed.
     *
     * `MongoEngine::status()` used to add the opcounters inside mongosh, which
     * concatenated their digits instead of adding them — mongosh keeps BSON
     * int64 as `Long`, and `Long` has no numeric primitive for `+` to reach.
     * Every `mongodb` row in this table therefore holds a glued-together
     * number rather than an operation count.
     *
     * They cannot be repaired: the original counters are not recoverable from
     * the concatenation, because nothing records how many digits each one
     * contributed. Leaving them costs more than deleting them — the Query
     * Monitor charts a delta between consecutive samples, so a poisoned row
     * keeps producing an impossible reading for the full 24h retention window,
     * and the first honest sample after the fix reads as a huge negative that
     * `max(0, …)` silently flattens to zero.
     *
     * Only the `mongodb` engine is touched. MariaDB and MySQL samples were
     * always parsed out of `SHOW GLOBAL STATUS` text and cast in PHP, so they
     * were never affected.
     */
    public function up(): void
    {
        DB::table('db_metrics')->where('engine', 'mongodb')->delete();
    }

    /**
     * Nothing to restore. The deleted rows held corrupt values, and a metrics
     * table that prunes itself to 24h refills within a day of sampling.
     */
    public function down(): void {}
};
