<?php

// Errors from the paid-addon routes Central calls. See AddonException for
// the HTTP status and machine `code` each one goes out with.

return [
    'not_installed' => ':addon is not installed on this server.',
    'licence_required' => ':addon has not been purchased for this server.',
    'site_not_registered' => 'This site is not registered with :addon yet.',
    'command_failed' => ':addon could not do that: :message',
    'bad_output' => ':addon answered something the panel could not read.',
    'timed_out' => ':addon did not finish in time.',
    'no_system_user' => 'This site has no system user.',
    'run_failed' => 'The addon command failed unexpectedly.',
    'unregistered' => 'Site unregistered.',
];
