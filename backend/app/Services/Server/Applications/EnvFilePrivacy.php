<?php

namespace App\Services\Server\Applications;

use App\Models\Application;
use App\Services\Server\Php\RuntimeOwnership;
use App\Services\Server\ServerOps;
use Throwable;

/**
 * Take world access off a site's `.env` without taking it from the account
 * that runs the site.
 *
 * Several ways a `.env` comes into being leave it 0644: Statamic's own
 * `create-project` script copies `.env.example`, Akaunting's, Mautic's and
 * PrestaShop's installers write theirs, and a git deploy seeds one from the
 * repository's example. The panel's own writes are private (the editor saves
 * 0600, installer secrets are 0640), so a site was only private once somebody
 * saved it or pressed "Fix permissions". Until then the web server account —
 * `www-data`, or `nobody` on OpenLiteSpeed — could read the database password
 * and APP_KEY (measured on 2026-09-29).
 *
 * Only ever narrows: `o-rwx,g-w` removes access and adds none, so a file the
 * user made 0600 stays 0600. The group follows the rule of
 * AbstractSiteInstaller::runtimePhpOwner(): the site user, or the web server
 * account where PHP runs as that — otherwise taking the "other" bit away
 * would take the site's own configuration away from it.
 *
 * Best-effort by design. It runs at the end of a provision and inside a
 * deploy, and a site that is up must not be failed over a file mode.
 */
class EnvFilePrivacy
{
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
            // path() throws when it cannot get an answer at all; that is a
            // reason to leave the file alone, not to fail the site.
            report($e);
        }
    }

    private function apply(Application $application): void
    {
        // Resolved here, not injected: ApplicationEnvironment depends on the
        // provisioner, which runs this — injecting it is a container cycle.
        $environment = app(ApplicationEnvironment::class);

        $application->loadMissing('systemUser');
        $user = $application->systemUser?->username;

        if ($user === null) {
            return;
        }

        // path() and a plain test rather than exists(): exists() also moves a
        // legacy `.env`, which is the environment screen's business and not
        // something a resync over every site should do on the way past.
        $path = $environment->path($application);

        if (! $this->serverOps->run(['runuser', '-u', $user, '--', 'test', '-f', $path], $this->context($application))->ok) {
            return;
        }

        if (! $this->ownership->runsAsOwnUser($application)) {
            // `-h`: a link at the path is changed itself, never followed.
            $group = (string) config('server.web_server_user', 'www-data');
            $this->serverOps->run(['chown', '-h', "{$user}:{$group}", $path], $this->context($application));
        }

        // As the site user, so a link planted at the path reaches only what
        // they could already change.
        $this->serverOps->run(['runuser', '-u', $user, '--', 'chmod', 'o-rwx,g-w', $path], $this->context($application));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Application $application): array
    {
        return ['feature' => 'application', 'op' => 'env_privacy', 'application' => $application->id];
    }
}
