<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The deployment row can say *why* it failed, not only *where*.
 *
 * `failed_step` names the step and `reference` points at a log the person
 * reading the screen usually cannot open. The application row has carried a
 * classified reason for a while; the deployment row — which is the screen
 * somebody actually opens after a failed deploy — had nowhere to put it.
 *
 * Additive and nullable: every existing row keeps its meaning, and a failure
 * nothing recognises still stores null rather than a guess.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('failed_reason')->nullable()->after('failed_step');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn('failed_reason');
        });
    }
};
