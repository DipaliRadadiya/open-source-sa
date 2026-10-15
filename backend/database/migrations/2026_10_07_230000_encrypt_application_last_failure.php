<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `applications.last_failure` is encrypted at rest (DS-08).
 *
 * It holds the last 50 lines of a container's own log from a failed readiness
 * check, and a container's first boot is exactly when it prints connection
 * strings and tokens. The API already shows those lines only to `app_log`
 * viewers; the row was plaintext, so they were in every database dump and
 * panel backup. Same treatment as `docker_secrets` next to it.
 *
 * `text` rather than `json`: ciphertext is not JSON, and MySQL/MariaDB check a
 * json column's contents. Rows already written are encrypted in place, so the
 * cast can read them; `down()` puts both back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('last_failure')->nullable()->change();
        });

        $this->rewrite(fn (string $value): string => Crypt::encryptString($value));
    }

    public function down(): void
    {
        $this->rewrite(fn (string $value): string => Crypt::decryptString($value));

        Schema::table('applications', function (Blueprint $table) {
            $table->json('last_failure')->nullable()->change();
        });
    }

    /**
     * @param  Closure(string): string  $transform
     */
    private function rewrite(Closure $transform): void
    {
        DB::table('applications')
            ->whereNotNull('last_failure')
            ->select(['id', 'last_failure'])
            ->chunkById(100, function ($rows) use ($transform): void {
                foreach ($rows as $row) {
                    DB::table('applications')->where('id', $row->id)
                        ->update(['last_failure' => $transform((string) $row->last_failure)]);
                }
            });
    }
};
