<?php

namespace App\Services\Panel;

use App\Exceptions\Admin\PanelUpdate\PanelUpdateHelperFailedException;
use App\Models\PanelUpdate;
use App\Services\Server\ServerOps;
use JsonException;

/** Executes the single root-owned updater command; no client input reaches it. */
class PanelUpdateRunner
{
    public function __construct(private ServerOps $serverOps) {}

    /** @return array{version:?string,commit:?string} */
    public function run(PanelUpdate $update): array
    {
        $helper = config('panel_update.helper_path');

        if (! is_string($helper) || ! str_starts_with($helper, '/')) {
            throw new PanelUpdateHelperFailedException('configuration');
        }

        $result = $this->serverOps->run(
            [$helper, '--update-id='.$update->getKey()],
            ['feature' => 'panel_update', 'update_id' => $update->getKey()],
            (int) config('panel_update.timeout_seconds', 1800),
        );

        if ($result->failed()) {
            throw new PanelUpdateHelperFailedException($result->reference);
        }

        try {
            $payload = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PanelUpdateHelperFailedException($result->reference);
        }

        if (! is_array($payload) || ! isset($payload['version'], $payload['commit'])
            || ! is_string($payload['version']) || ! is_string($payload['commit'])) {
            throw new PanelUpdateHelperFailedException($result->reference);
        }

        return ['version' => $payload['version'], 'commit' => $payload['commit']];
    }
}
