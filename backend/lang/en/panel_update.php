<?php

/*
 * Panel-update feature strings.
 *
 * Sent to the admin screen, so every user-visible word lives here rather than
 * on the frontend. The status labels are referenced from
 * `App\Enums\PanelUpdateStatus::label()`; reason messages are referenced
 * from `App\Models\PanelUpdate::message()`. Both are read in the viewer's
 * locale (via the SetLocale middleware) and never hardcoded English.
 */

return [

    // Where one panel update got to. Shown as a badge on the row.
    'status' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'rolled_back' => 'Rolled back',
    ],

    // Returned with the 202 response so the toast can say something other
    // than "OK" while the worker is still queued.
    'queued' => 'The panel update has been queued and will start shortly.',

    // Reason codes written by the worker / the queueing action. The lang
    // keys here are referenced from `PanelUpdate::message()`; the model
    // falls back to `unknown` when a key is missing so an unrecognised
    // reason never reaches the user as a raw code.
    'reason' => [
        'unsupported' => 'The panel update worker is not implemented yet. Quote the reference to support.',
        'worker' => 'The update worker stopped unexpectedly. Quote the reference to support.',
        'unknown' => 'The panel update failed. Quote the reference to support.',
    ],

];