<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Machine accounts. A system user owns API tokens but is not a person:
        // it never logs in, is not listed or editable in the admin area, and
        // is not counted in the dashboard's user totals.
        //
        // Additive rather than folded into the create migration because real
        // installations already hold user rows.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
