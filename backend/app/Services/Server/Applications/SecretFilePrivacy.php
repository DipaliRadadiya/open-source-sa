<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use Throwable;

/**
 * Keep a site's secret files away from every account that does not run it.
 *
 * Two gaps, both measured on the test servers on 2026-09-29:
 *
 *  - Several ways a `.env` comes into being leave it 0644: Statamic's own
 *    `create-project` script copies `.env.example`, Akaunting's, Mautic's and
 *    PrestaShop's installers write theirs, and a git deploy seeds one from the
 *    repository's example. The web server account could read the database
 *    password and APP_KEY until somebody saved the file.
 *  - The installers wrote `wp-config.php` and the other config files 0640,
 *    and on OpenLiteSpeed the web server account (`nobody`) is a member of
 *    every site user's group so it can write the site's logs. Group-readable
 *    was readable by it.
 *
 * The mode follows RuntimeOwnership::secretFileMode(): where the site runs as
 * its own user the group is stripped too (`go-rwx`); where PHP runs as the web
 * server's account the group is handed to that account (`chown -h`) and only
 * "other" is stripped, or the site would lose its own configuration.
 *
 * Only ever narrows — the chmod removes access and adds none — so a file the
 * user tightened stays as they left it. Best-effort by design: it runs at the
 * end of a provision, inside a deploy and from `sites:resync`, and a site that
 * is up must not be failed over a file mode.
 */
class SecretFilePrivacy
{
    /**
     * Secret files each installer writes, relative to the document root.
     *
     * @var array<string, array<int, string>>
     */
    public const FILES = [
        'wordpress' => ['wp-config.php'],
        'moodle' => ['config.php'],
        'phpmyadmin' => ['config.inc.php'],
        'mautic' => ['config/local.php'],
        'nodebb' => ['config.json'],
        'nodered' => ['settings.js'],
    ];

    public function __construct(
        private ServerOps $serverOps,
        private RuntimeOwnership $ownership,
    ) {}

    /**
     * Also run by `sites:resync`, which is how sites installed before this
     * existed are repaired.
     */
    public function narrow(Application $application): void
    {
        try {
            $this->apply($application);
        } catch (Throwable $e) {
            // Resolving the `.env` path throws when it cannot get an answer at
            // all; that is a reason to leave the files alone, not to fail the
            // site.
            report($e);
        }
    }

    private function apply(Application $application): void
    {
        $application->loadMissing('systemUser');
        $user = $application->systemUser?->username;

        if ($user === null) {
            return;
        }

        $ownUser = $this->ownership->runsAsOwnUser($application);
        $group = (string) config('server.web_server_user', 'www-data');

        foreach ($this->paths($application) as $path) {
            // A plain test as the user: absent is the common case (most types
            // have no `.env`), and nothing here should run against a path that
            // is not there.
            if (! $this->serverOps->run(['runuser', '-u', $user, '--', 'test', '-f', $path], $this->context($application))->ok) {
                continue;
            }

            if (! $ownUser) {
                // `-h`: a link at the path is changed itself, never followed.
                $this->serverOps->run(['chown', '-h', "{$user}:{$group}", $path], $this->context($application));
            }

            // As the site user, so a link planted at the path reaches only
            // what they could already change.
            $this->serverOps->run(
                ['runuser', '-u', $user, '--', 'chmod', $ownUser ? 'go-rwx' : 'o-rwx,g-w', $path],
                $this->context($application),
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function paths(Application $application): array
    {
        // Resolved here, not injected: ApplicationEnvironment depends on the
        // provisioner, which runs this — injecting it is a container cycle.
        // path() rather than exists(): exists() also moves a legacy `.env`,
        // which is the environment screen's business and not something a
        // resync over every site should do on the way past.
        $paths = [app(ApplicationEnvironment::class)->path($application)];

        $root = rtrim($application->documentRoot(), '/');

        foreach (self::FILES[(string) $application->site_type] ?? [] as $relative) {
            $paths[] = "{$root}/{$relative}";
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application): array
    {
        return ['feature' => 'application', 'op' => 'secret_privacy', 'application' => $application->id];
    }
}
