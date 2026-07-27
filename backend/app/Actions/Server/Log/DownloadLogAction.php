<?php

namespace App\Actions\Server\Log;

use App\Services\ActivityLogger;
use App\Services\Server\LogManager;

class DownloadLogAction
{
    public function __construct(
        private LogManager $logs,
        private ActivityLogger $activityLogger,
    ) {}

    /**
     * Resolve a source key to its real path (with existence/readability
     * guards) and record the download, returning the path to stream.
     */
    public function execute(string $key): string
    {
        $source = $this->logs->find($key);

        if (! $source || ! is_file($source['path'])) {
            abort(404, __('errors/log.not_found'));
        }

        if (! is_readable($source['path'])) {
            abort(403, __('errors/log.unreadable'));
        }

        $this->activityLogger->log('log.downloaded', null, ['log' => $source['label']]);

        return $source['path'];
    }
}
