<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The original migration created the FK without an onDelete clause,
        // which defaults to RESTRICT. The DB refuses to delete an application
        // that any SiteClone record still references.  SQLite does not support
        // ALTER TABLE ... DROP CONSTRAINT / ADD CONSTRAINT ON DELETE, so we
        // rebuild the table schema cleanly.
        DB::statement("
            CREATE TABLE clones_backup AS
                SELECT id, source_application_id, target_application_id,
                       user_id, name, domain, status, current_step,
                       reason, reference, started_at, finished_at,
                       created_at, updated_at
                FROM clones
        ");

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('DROP TABLE clones');

        DB::statement("
            CREATE TABLE clones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_application_id INTEGER NOT NULL,
                target_application_id INTEGER NULL,
                user_id INTEGER NULL,
                name VARCHAR(255) NULL,
                domain VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                current_step VARCHAR(40) NULL,
                reason VARCHAR(40) NULL,
                reference VARCHAR(40) NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                CONSTRAINT clones_source_application_id_foreign
                    FOREIGN KEY (source_application_id)
                    REFERENCES applications (id)
                    ON DELETE CASCADE,
                CONSTRAINT clones_target_application_id_foreign
                    FOREIGN KEY (target_application_id)
                    REFERENCES applications (id)
                    ON DELETE SET NULL,
                CONSTRAINT clones_user_id_foreign
                    FOREIGN KEY (user_id)
                    REFERENCES users (id)
                    ON DELETE SET NULL
            )
        ");

        DB::statement('INSERT INTO clones SELECT * FROM clones_backup');
        DB::statement('DROP TABLE clones_backup');

        DB::statement('PRAGMA foreign_keys = ON');

        // Re-create the indexes that the original migration defined.
        DB::statement('CREATE INDEX clones_source_application_id_status_index
                       ON clones (source_application_id, status)');
        DB::statement('CREATE INDEX clones_target_application_id_index
                       ON clones (target_application_id)');
    }

    public function down(): void
    {
        DB::statement("
            CREATE TABLE clones_backup AS
                SELECT id, source_application_id, target_application_id,
                       user_id, name, domain, status, current_step,
                       reason, reference, started_at, finished_at,
                       created_at, updated_at
                FROM clones
        ");

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('DROP TABLE clones');

        DB::statement("
            CREATE TABLE clones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_application_id INTEGER NOT NULL,
                target_application_id INTEGER NULL,
                user_id INTEGER NULL,
                name VARCHAR(255) NULL,
                domain VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                current_step VARCHAR(40) NULL,
                reason VARCHAR(40) NULL,
                reference VARCHAR(40) NULL,
                started_at DATETIME NULL,
                finished_at DATETIME NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                CONSTRAINT clones_source_application_id_foreign
                    FOREIGN KEY (source_application_id)
                    REFERENCES applications (id),
                CONSTRAINT clones_target_application_id_foreign
                    FOREIGN KEY (target_application_id)
                    REFERENCES applications (id),
                CONSTRAINT clones_user_id_foreign
                    FOREIGN KEY (user_id)
                    REFERENCES users (id)
            )
        ");

        DB::statement('INSERT INTO clones SELECT * FROM clones_backup');
        DB::statement('DROP TABLE clones_backup');

        DB::statement('PRAGMA foreign_keys = ON');

        DB::statement('CREATE INDEX clones_source_application_id_status_index
                       ON clones (source_application_id, status)');
        DB::statement('CREATE INDEX clones_target_application_id_index
                       ON clones (target_application_id)');
    }
};
