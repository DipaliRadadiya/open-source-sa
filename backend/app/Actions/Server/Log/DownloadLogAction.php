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

        if (! $source || $this->logs->describe($source) === null) {
            abort(404, __('errors/log.not_found'));
        }

        // A source the panel cannot open has nothing to stream. Piping it out
        // through sudo would hold an FPM worker for the whole transfer, which
        // is the objection that made backup downloads a presigned URL rather
        // than a stream — and the journal is not a file to begin with. Refused
        // by name, so the screen can say why instead of failing oddly.
        if (($source['kind'] ?? 'file') !== 'file') {
            abort(422, __('errors/log.not_downloadable'));
        }

        if (! is_readable($source['path'])) {
            abort(403, __('errors/log.unreadable'));
        }

        $this->activityLogger->log('log.downloaded', null, ['log' => $source['label']]);

        return $source['path'];
    }
}
