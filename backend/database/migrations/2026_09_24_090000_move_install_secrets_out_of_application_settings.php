<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Take the installer's passwords out of `settings`.
 *
 * A one-click site's admin password was saved into `applications.settings`,
 * a plain JSON column, because the installer runs on the queue after the
 * create request has returned and has no other way to receive it. It then
 * stayed there for the life of the site and `GET /applications/{id}` returned
 * it, readable by anyone allowed to *view* the site. Found live on 2026-09-24.
 *
 * The installer needs it until the install succeeds and never again, so:
 *
 * - a site still waiting to install (or whose install failed, so it can be
 *   retried) keeps its secrets, moved into `install_secrets`, which the model
 *   encrypts;
 * - an installed site loses them.
 *
 * The key list is a snapshot rather than read from the site types: a migration
 * must mean the same thing on the day it runs as on the day it was written.
 * These are every `password` field any site type declared as of today.
 *
 * `down()` puts back what is still held, which is only the not-yet-installed
 * sites'. A password removed from an installed site is not recoverable, and
 * nothing needs it: the panel never reads it after the install.
 */
return new class extends Migration
{
    private const KEYS = ['admin_password', 'mailer_password'];

    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->text('install_secrets')->nullable()->after('settings');
        });

        DB::table('applications')->whereNotNull('settings')->orderBy('id')->each(function (object $row): void {
            $settings = json_decode((string) $row->settings, true);

            if (! is_array($settings)) {
                return;
            }

            $secrets = array_intersect_key($settings, array_flip(self::KEYS));

            if ($secrets === []) {
                return;
            }

            $keep = $row->status !== 'active';

            DB::table('applications')->where('id', $row->id)->update([
                'settings' => json_encode(array_diff_key($settings, $secrets)),
                // The same format the `encrypted:array` cast writes and reads.
                'install_secrets' => $keep ? Crypt::encryptString(json_encode($secrets)) : null,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('applications')->whereNotNull('install_secrets')->orderBy('id')->each(function (object $row): void {
            $secrets = json_decode(Crypt::decryptString((string) $row->install_secrets), true);
            $settings = json_decode((string) $row->settings, true);

            DB::table('applications')->where('id', $row->id)->update([
                'settings' => json_encode(array_merge(is_array($settings) ? $settings : [], is_array($secrets) ? $secrets : [])),
            ]);
        });

        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('install_secrets');
        });
    }
};
