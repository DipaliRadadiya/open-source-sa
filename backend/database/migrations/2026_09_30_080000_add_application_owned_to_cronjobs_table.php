<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which cron jobs the panel created for a site itself.
 *
 * Deleting a site keeps its cron jobs on purpose — a job the user added may be
 * something they still want running. The background job a Nextcloud or Moodle
 * install adds is not that: it runs the site's own `cron.php`, and once the
 * site is gone it fails every tick and holds the site user busy. Nothing
 * recorded which of the two a row was, so both were kept.
 *
 * Existing rows are marked only where it is certain: the installer's exact
 * name suffix, its exact command shape, that type's own script, inside that
 * site's own directory. A job the user renamed or edited does not match, and
 * stays theirs.
 */
return new class extends Migration
{
    /** The script each installer schedules, relative to its document root. */
    private const SCRIPTS = [
        'nextcloud' => 'cron.php',
        'moodle' => 'admin/cli/cron.php',
    ];

    public function up(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->boolean('application_owned')->default(false)->after('application_id');
        });

        $candidates = DB::table('cronjobs')
            ->join('applications', 'applications.id', '=', 'cronjobs.application_id')
            ->join('system_users', 'system_users.id', '=', 'applications.system_user_id')
            ->whereIn('applications.site_type', array_keys(self::SCRIPTS))
            ->get(['cronjobs.id', 'cronjobs.name', 'cronjobs.command', 'applications.id as app_id',
                'applications.site_type', 'applications.slug', 'system_users.home_path']);

        foreach ($candidates as $row) {
            if ($this->isInstallerJob($row)) {
                DB::table('cronjobs')->where('id', $row->id)->update(['application_owned' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('cronjobs', function (Blueprint $table) {
            $table->dropColumn('application_owned');
        });
    }

    private function isInstallerJob(object $row): bool
    {
        if (! str_ends_with((string) $row->name, ' background jobs #'.$row->app_id)) {
            return false;
        }

        // `<php binary> -f <path>`, exactly what AbstractPhpInstaller writes,
        // and nothing else on the line.
        if (preg_match('#^/\S+ -f (/\S+)$#', (string) $row->command, $match) !== 1) {
            return false;
        }

        $root = rtrim((string) $row->home_path, '/').'/'.$row->slug.'/';

        return str_starts_with($match[1], $root)
            && str_ends_with($match[1], '/'.self::SCRIPTS[$row->site_type]);
    }
};
