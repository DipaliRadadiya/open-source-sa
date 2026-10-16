<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backups of a database on its own (v7's `type=database` with only a
 * `database_id`), and archived backups.
 *
 * Until now every target, backup and restore belonged to a site: three
 * required `application_id` columns. A database with no site could only be
 * exported by hand. Each of the three now belongs to a site **or** a
 * database — exactly one, enforced where the rows are written.
 *
 * `backups.database_id` is `nullOnDelete`, and `backups` gains the name and
 * engine of what it was taken from. Deleting a database leaves its backups
 * listed, downloadable and restorable into another database ("archived"),
 * rather than deleting the rows and stranding the archives in the bucket with
 * nothing pointing at them — which is what deleting a site does today.
 * `backups.backup_target_id` becomes nullable for the same reason: the
 * schedule goes with the database, its history does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_targets', function (Blueprint $table): void {
            $table->unsignedBigInteger('application_id')->nullable()->change();
            $table->foreignId('database_id')->nullable()->after('application_id')->constrained()->cascadeOnDelete();
            $table->unique('database_id');
        });

        Schema::table('backups', function (Blueprint $table): void {
            $table->unsignedBigInteger('backup_target_id')->nullable()->change();
            $table->unsignedBigInteger('application_id')->nullable()->change();
            $table->foreignId('database_id')->nullable()->after('application_id')->constrained()->nullOnDelete();
            // What the backup was taken from, kept after it is gone.
            $table->string('subject_name')->nullable()->after('database_id');
            $table->string('subject_engine', 32)->nullable()->after('subject_name');
            $table->index(['database_id', 'status']);
        });

        Schema::table('restores', function (Blueprint $table): void {
            $table->unsignedBigInteger('application_id')->nullable()->change();
            $table->foreignId('database_id')->nullable()->after('application_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Rows that belong to no site cannot survive NOT NULL. They exist only
        // because of this migration, so removing them is the reverse of it.
        DB::table('restores')->whereNull('application_id')->delete();
        DB::table('backups')->whereNull('application_id')->delete();
        DB::table('backups')->whereNull('backup_target_id')->delete();
        DB::table('backup_targets')->whereNull('application_id')->delete();

        Schema::table('restores', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('database_id');
            $table->unsignedBigInteger('application_id')->nullable(false)->change();
        });

        Schema::table('backups', function (Blueprint $table): void {
            // The foreign key first: MySQL/MariaDB refuse to drop an index a
            // foreign key still uses (found testing this on MariaDB).
            $table->dropForeign(['database_id']);
            $table->dropIndex(['database_id', 'status']);
            $table->dropColumn('database_id');
            $table->dropColumn(['subject_name', 'subject_engine']);
            $table->unsignedBigInteger('application_id')->nullable(false)->change();
            $table->unsignedBigInteger('backup_target_id')->nullable(false)->change();
        });

        Schema::table('backup_targets', function (Blueprint $table): void {
            $table->dropForeign(['database_id']);
            $table->dropUnique(['database_id']);
            $table->dropColumn('database_id');
            $table->unsignedBigInteger('application_id')->nullable(false)->change();
        });
    }
};
