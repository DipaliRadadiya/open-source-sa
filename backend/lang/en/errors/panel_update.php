<?php

return [
    // Rendered when the queueing action refuses a second POST while another
    // panel update is already in flight (status pending or running).
    // No placeholders: the renderer passes no replacements.
    'already_in_progress' => 'A panel update is already in progress. Wait for it to finish before starting another.',
];
