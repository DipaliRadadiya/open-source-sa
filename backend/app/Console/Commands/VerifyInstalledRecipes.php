<?php

namespace App\Console\Commands;

use App\Exceptions\Server\Application\ProvisioningFailedException;
use App\Models\Application;
use App\Services\Applications\SiteTypeManager;
use App\Services\Applications\Types\AbstractDockerAppType;
use App\Services\Server\Applications\Installers\DockerAppInstaller;
use Illuminate\Console\Command;

final class VerifyInstalledRecipes extends Command
{
    protected $signature = 'recipes:verify-installed {--site=}';

    protected $description = 'Compare installed one-click compose files without changing any site';

    public function handle(SiteTypeManager $types, DockerAppInstaller $installer): int
    {
        $sites = Application::query()->where('serving_profile', 'docker');
        if ($this->option('site') !== null) {
            $sites->where('id', $this->option('site'));
        }

        $different = false;
        foreach ($sites->lazyById() as $application) {
            if (! $types->find((string) $application->site_type) instanceof AbstractDockerAppType) {
                continue;
            }

            try {
                $rendered = $installer->renderFor($application, $application->url());
            } catch (ProvisioningFailedException $e) {
                $this->line($application->id.' DIFFER render failed (reference '.$e->reference.')');
                $different = true;

                continue;
            }

            if ($rendered === null) {
                $this->line($application->id.' SKIPPED');

                continue;
            }

            $stored = (string) $application->compose;
            if ($rendered === $stored) {
                $this->line($application->id.' MATCH');

                continue;
            }

            $before = explode("\n", $stored);
            $after = explode("\n", $rendered);
            $first = null;
            $onlyImages = count($before) === count($after);
            for ($index = 0; $index < max(count($before), count($after)); $index++) {
                if (($before[$index] ?? null) === ($after[$index] ?? null)) {
                    continue;
                }
                $first ??= $index + 1;
                if (preg_match('/^\s*image: /', $before[$index] ?? '') !== 1
                    || preg_match('/^\s*image: /', $after[$index] ?? '') !== 1) {
                    $onlyImages = false;
                }
            }

            if ($onlyImages) {
                $this->line($application->id.' MATCH_EXCEPT_IMAGE');
            } else {
                $this->line($application->id.' DIFFER first diff at line '.$first);
                $different = true;
            }
        }

        return $different ? self::FAILURE : self::SUCCESS;
    }
}
