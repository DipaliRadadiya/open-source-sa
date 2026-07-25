<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Split the activity `action` into two indexed columns: `type` (the
     * entity, e.g. `system_user`) and `action` (the verb, e.g. `created`) —
     * so both can be filtered with fast exact-match index lookups. The lang
     * key stays `activity.<type>.<action>`, so translations are unchanged.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('type')->default('')->after('user_id')->index();
            $table->index('action');
        });

        // Backfill: action "system_user.created" -> type "system_user", action "created".
        DB::table('activity_logs')->orderBy('id')->each(function ($row) {
            DB::table('activity_logs')->where('id', $row->id)->update([
                'type' => Str::before($row->action, '.'),
                'action' => Str::after($row->action, '.'),
            ]);
        });
    }

    public function down(): void
    {
        // Recombine into the single dotted action, then drop type.
        DB::table('activity_logs')->orderBy('id')->each(function ($row) {
            DB::table('activity_logs')->where('id', $row->id)->update([
                'action' => $row->type.'.'.$row->action,
            ]);
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['action']);
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
