<?php

namespace App\Services\Server\Applications\Installers;

use App\Models\Application;

/**
 * Statamic — flat-file CMS.
 *
 * Two things set it apart from everything else in the marketplace. It stores
 * its content in files rather than a database, so it asks for none. And it is
 * a Laravel application, so it serves from `public/` — pointing the web server
 * at the project root would publish its source and its `.env`.
 *
 * It is also the one installer that puts a password on a command line, and
 * the reason is worth stating rather than hiding. `make:user` has two modes:
 * given an email argument it runs non-interactively and takes `--password`;
 * given none it prompts through Laravel Prompts, which — unlike Symfony's
 * question helper, and unlike Yii's — refuses to read a pipe and throws. That
 * was measured, not assumed. So there is no stdin path here.
 *
 * The alternative was writing Statamic's user file ourselves: a YAML file with
 * a bcrypt hash, plus a companion under `storage/`. That is another project's
 * private storage format, and getting it subtly wrong on their next release
 * means a login that fails with no clue why. A password visible in `ps` for
 * the second the command takes, on a machine whose only other accounts are the
 * site users this panel created, is the smaller risk of the two.
 */
class StatamicInstaller extends AbstractSiteInstaller
{
    public function siteType(): string
    {
        return 'statamic';
    }

    /**
     * Content lives in files. A database would sit empty and its credentials
     * would be a secret nobody needs.
     */
    public function needsDatabase(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function install(Application $application, string $documentRoot, array $context): array
    {
        $settings = $application->settings ?? [];
        // `public/` is served; Statamic itself lives above it.
        $projectRoot = dirname($documentRoot);
        $steps = [];

        $this->run('download', [
            (string) config('server.composer_binary', 'composer'),
            'create-project', 'statamic/statamic', $projectRoot,
            '--no-interaction', '--no-progress',
        ], $application);
        $steps[] = 'download';

        $this->run('extract', [
            'chown', '-R', "{$application->systemUser->username}:{$application->systemUser->username}", $projectRoot,
        ], $application);
        $steps[] = 'extract';

        // The email argument selects the non-interactive path. `--password` is
        // on the command line because Statamic offers no other way in — see
        // the class note.
        $this->runAsSiteUser('install_app', $application, array_filter([
            $this->phpBinary($application), 'please', 'make:user',
            (string) ($settings['admin_email'] ?? ''),
            '--super',
            '--password='.($settings['admin_password'] ?? ''),
        ]), null, $projectRoot);
        $steps[] = 'install_app';

        return $steps;
    }
}
