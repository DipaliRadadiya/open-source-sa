<?php

namespace App\Console\Commands;

use App\Services\Applications\SiteTypeManager;
use App\Services\Runtime\AppPackageCatalog;
use Illuminate\Console\Command;

class RefreshAppPackages extends Command
{
    protected $signature = 'runtimes:refresh-app-packages';

    protected $description = 'Refresh the Node range each one-click application\'s current release declares';

    public function handle(AppPackageCatalog $catalog, SiteTypeManager $types): int
    {
        // Asked of the site types rather than listed here, so adding a package
        // to a type is the whole change — a second list is how the first one
        // goes stale, which is the bug this command exists to prevent.
        $packages = [];

        foreach ($types->all() as $type) {
            $package = $type->npmPackage();

            if ($package === null) {
                continue;
            }

            $packages[$package] = (string) config("server.installers.{$type->name()}.version", 'latest');
        }

        if ($packages === []) {
            $this->info('no packages to refresh');

            return self::SUCCESS;
        }

        $this->info("app packages: {$catalog->refresh($packages)} on record");

        return self::SUCCESS;
    }
}
