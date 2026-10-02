<?php

namespace App\Services\Addons;

use App\Exceptions\Addons\AddonException;
use App\Models\Application;
use App\Services\Server\ServerOps;
use App\Services\Server\WebServers\WebServerManager;

/**
 * The InsightHub toolkit (sv-insighthub-toolkit): access-log analytics read
 * from its own SQLite database.
 *
 * Sites are known to it by its own id, which `register()` stores on the
 * application. Its `--key` is the panel's reference (`v8-<id>`), so a lost
 * answer to `applications:add` is recovered rather than added twice.
 */
class InsightHubToolkit extends AddonCli
{
    public function __construct(ServerOps $serverOps, private WebServerManager $webServers)
    {
        parent::__construct($serverOps);
    }

    public function name(): string
    {
        return 'insighthub-toolkit';
    }

    public function label(): string
    {
        // The product's current name. It launched as InsightHub, which is still
        // what the binary, its repository and its database are called.
        return 'Log Monitoring Suite';
    }

    protected function binary(): string
    {
        return (string) config('server.addons.insighthub.binary');
    }

    protected function workdir(): ?string
    {
        return (string) config('server.addons.insighthub.workdir');
    }

    public function key(Application $application): string
    {
        return 'v8-'.$application->id;
    }

    /**
     * Register the site (or adopt the record already carrying its key) and
     * remember InsightHub's id for it.
     *
     * @return array<string, mixed> the addon's answer
     */
    public function register(Application $application): array
    {
        $timeout = (int) config('server.addons.sync_timeout');

        try {
            $answer = $this->run([
                'applications:add',
                '--name='.$application->slug,
                '--system-user='.$application->systemUser?->username,
                '--domain='.$application->domain,
                '--key='.$this->key($application),
                '--web-server='.$this->webServers->driver()->name(),
                '--ssl='.($application->certificate?->servable() ? 'true' : 'false'),
                '--monitoring=true',
            ], $timeout, ['application' => $application->id]);
        } catch (AddonException $e) {
            // Already registered under this site's own key — a retry after a
            // lost answer. Anything else holding the name or domain is a real
            // conflict and is reported as one.
            if (($e->details['code'] ?? null) === 'conflict' && ($e->details['field'] ?? null) === 'key') {
                $application->forceFill(['insighthub_id' => (int) $e->details['application_id']])->save();

                return ['status' => 'success', 'message' => 'Application already registered', 'application' => ['id' => (int) $e->details['application_id']]];
            }

            throw $e;
        }

        $application->forceFill(['insighthub_id' => (int) ($answer['application']['id'] ?? 0) ?: null])->save();

        return $answer;
    }

    /**
     * Unregister the site; one InsightHub has never heard of is already gone.
     */
    public function unregister(Application $application): void
    {
        try {
            $this->run(['applications:remove', '--key='.$this->key($application)], (int) config('server.addons.sync_timeout'), ['application' => $application->id]);
        } catch (AddonException $e) {
            if (($e->details['code'] ?? null) !== 'not_found') {
                throw $e;
            }
        }

        $application->forceFill(['insighthub_id' => null])->save();
    }
}
