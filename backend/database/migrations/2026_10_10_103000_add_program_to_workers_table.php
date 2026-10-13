<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table): void {
            // Null preserves the installed sv-worker-{slug} program. A save
            // can move it explicitly; a schema upgrade never stops a worker.
            $table->string('program', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table): void {
            $table->dropUnique(['program']);
            $table->dropColumn('program');
        });
    }
};
