<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * npm | yarn | pnpm | bun — only meaningful for the ssr/csr rendering
     * types a git-deployed Node app can have. Nullable for every other
     * application, and for Node apps created before this existed: their
     * `build_command` keeps running exactly as it always has.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('package_manager')->nullable()->after('start_command');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('package_manager');
        });
    }
};
