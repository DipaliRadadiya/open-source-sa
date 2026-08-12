<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A database user whose password the panel does not know.
 *
 * Every user the panel creates has one, stored encrypted so the connection
 * string can be shown later. A user adopted from a migrated server does not:
 * the engine holds a hash, and a hash cannot be turned back into a password.
 *
 * The column was NOT NULL, so adoption had exactly two options — invent a
 * value, or leave the user out. Inventing one is worse than it sounds: the
 * panel would then hand out a connection string that looks right and does not
 * work, which is the kind of wrong answer that costs an hour to diagnose.
 * Null means "we do not know", and the resource says so instead of guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_users', function (Blueprint $table) {
            $table->text('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('database_users', function (Blueprint $table) {
            $table->text('password')->nullable(false)->change();
        });
    }
};
