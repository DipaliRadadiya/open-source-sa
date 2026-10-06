<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of a container site's volumes its backup captures.
 *
 * **Nullable, and null means every volume — including ones added later.** That
 * is why it is nullable rather than defaulting to the volumes a site has today:
 * under null a site that gains a volume next month stays covered, where a list
 * captured at setup silently stops covering it and nothing anywhere reports the
 * gap. An explicit list is for someone who has deliberately excluded something.
 *
 * No default and no backfill. Every existing row belongs to a host-served site —
 * no container site has a backup target at all yet, because container site types
 * only stop stripping `app_backup` in this same change — so there is nothing to
 * migrate and nothing the column would mean for them. A default added to a new
 * column retroactively changes every existing row, which is not wanted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_targets', function (Blueprint $table): void {
            $table->json('volume_scope')->nullable()->after('database_excludes');
        });
    }

    public function down(): void
    {
        Schema::table('backup_targets', function (Blueprint $table): void {
            $table->dropColumn('volume_scope');
        });
    }
};
