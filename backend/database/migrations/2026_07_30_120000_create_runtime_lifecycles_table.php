<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support and end-of-life dates for Node and PHP majors.
 *
 * A table rather than the cache, because it is not a cache. It is a small,
 * slow-moving reference set fetched from upstream, and `php artisan
 * optimize:clear` — which runs on deploy — would wipe it, leaving every
 * lifecycle badge blank until the next daily refresh. Reference data that
 * disappears on deploy is a bug, not a cold cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_lifecycles', function (Blueprint $table): void {
            $table->id();
            // 'node' | 'php'
            $table->string('runtime', 20);
            // Node is supported per major ('22'); PHP per minor ('8.4').
            $table->string('version', 20);
            // 'current' | 'lts' | 'maintenance' | 'active' | 'security' | 'eol'
            $table->string('status', 20);
            $table->date('eol_date')->nullable();
            // Node only — PHP has no LTS releases.
            $table->string('lts_name', 40)->nullable();
            $table->timestamps();

            $table->unique(['runtime', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_lifecycles');
    }
};
