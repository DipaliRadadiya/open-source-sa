<?php

namespace App\Actions\Server\Docker;

use App\Models\Registry;
use App\Services\ActivityLogger;

class UpdateRegistry
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * Apply a partial change, where omission preserves.
     *
     * A form that renders an empty password box must not wipe a working
     * credential when somebody saves a renamed registry — so the token is merged
     * rather than assigned, and a null is treated as "not sent".
     *
     * @param  array{name?: string, registry?: string, username?: string, token?: string|null}  $data
     */
    public function execute(Registry $registry, array $data): Registry
    {
        $registry->fill(array_filter([
            'name' => $data['name'] ?? null,
            'registry' => $data['registry'] ?? null,
            'username' => $data['username'] ?? null,
        ], fn ($value): bool => $value !== null));

        $rotated = ($data['token'] ?? null) !== null;

        if ($rotated) {
            $registry->mergeConfig(['token' => $data['token']]);
        }

        // A stored "connected" describes the credential that was tested. Rotating
        // the token, or pointing the row at a different host, makes the green tick
        // a claim about something that is no longer there — and the tick is the
        // one thing this row exists to answer.
        if ($rotated || $registry->isDirty(['registry', 'username'])) {
            $registry->forgetTestResult();
        }

        $registry->save();

        $this->activityLogger->log('registry.updated', $registry, [
            'name' => $registry->name,
            'registry' => $registry->registry,
            'username' => $registry->username,
            // Whether the secret moved, never what it moved to. This is the
            // audit question that actually gets asked after an incident.
            'token_rotated' => $rotated,
        ]);

        return $registry;
    }
}
