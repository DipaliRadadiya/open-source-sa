<?php

namespace App\Actions\Server\Application;

use App\Enums\WafMode;
use App\Models\Application;
use App\Services\ActivityLogger;
use App\Services\Server\Applications\Waf8GManager;

class UpdateApplicationWaf
{
    public function __construct(
        private Waf8GManager $waf,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * @param  array<int, string>|null  $categories  null leaves the stored list alone
     * @param  array<int, string>|null  $exceptions  null leaves the stored list alone
     * @param  array<int, string>|null  $customRules  null leaves the stored list alone
     */
    public function execute(
        Application $application,
        bool $enabled,
        WafMode $mode,
        ?array $categories = null,
        ?array $exceptions = null,
        ?array $customRules = null,
    ): void {
        $application->load('systemUser', 'wafRules');

        $this->waf->apply($application, $enabled, $mode, $categories, $exceptions, $customRules);

        $this->activityLogger->log('application.waf_updated', $application, [
            'name' => $application->name,
        ]);
    }
}
