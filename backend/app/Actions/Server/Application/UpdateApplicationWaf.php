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
     * @param  array<int, string>  $categories
     * @param  array<int, string>  $exceptions
     * @param  array<int, string>  $customRules
     */
    public function execute(
        Application $application,
        bool $enabled,
        WafMode $mode,
        array $categories,
        array $exceptions,
        array $customRules,
    ): void {
        $application->load('systemUser');

        $this->waf->apply($application, $enabled, $mode, $categories, $exceptions, $customRules);

        $this->activityLogger->log('application.waf_updated', $application, [
            'name' => $application->name,
        ]);
    }
}
