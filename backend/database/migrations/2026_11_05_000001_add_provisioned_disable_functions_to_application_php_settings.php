<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `disable_functions` list a PHP site was provisioned with (RC-3,
 * 2026-11-05, Dipali).
 *
 * "Changed by the owner" has to be judged against what the site was given, and
 * the preset in the code is not that: it moves between releases, so every
 * untouched site provisioned by an earlier one read "changed". Null on a site
 * provisioned before this column — those keep being compared with the current
 * preset for their type, which is what they were compared with already.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_php_settings', function (Blueprint $table) {
            $table->text('provisioned_disable_functions')->nullable()->after('disable_functions');
        });
    }

    public function down(): void
    {
        Schema::table('application_php_settings', function (Blueprint $table) {
            $table->dropColumn('provisioned_disable_functions');
        });
    }
};
