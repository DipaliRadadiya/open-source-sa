<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the thing being installed was absent when the install was
 * *requested*, recorded once and never revised.
 *
 * Installers ask the box "is this already here?" to decide whether the
 * configuration is theirs to write. On a first attempt that is the right
 * question. On a retry after a part-finished attempt it is the wrong one: the
 * package is now present, so the installer concludes somebody else put it
 * there and skips the setup — which for MongoDB means leaving authentication
 * off on a database the panel believes it just installed successfully.
 *
 * Nullable, because every row written before this column existed has no
 * answer, and inventing one would be inventing the very fact this exists to
 * stop being guessed. A null means "nobody recorded it", and the installer
 * falls back to asking the box, which is the behaviour it had all along.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_installs', function (Blueprint $table) {
            $table->boolean('was_absent')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('runtime_installs', function (Blueprint $table) {
            $table->dropColumn('was_absent');
        });
    }
};
