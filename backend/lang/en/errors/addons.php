<?php

// Errors from the paid-addon routes Central calls. See AddonException for
// the HTTP status and machine `code` each one goes out with.

return [
    'not_installed' => ':addon is not installed on this server.',
    'licence_required' => ':addon has not been purchased for this server.',
    'site_not_registered' => 'This application is not registered with :addon yet.',
    'command_failed' => ':addon could not do that: :message',
    'bad_output' => ':addon answered something the panel could not read.',
    'timed_out' => ':addon did not finish in time.',
    'no_system_user' => 'This application has no system user.',
    'run_failed' => 'The addon command failed unexpectedly.',
    'unregistered' => 'Application unregistered.',
    'option_required' => ':option is required for this report.',
    'redis_unavailable' => 'Redis is not running on this server, so Object Cache Pro cannot be set up.',
    'redis_too_old' => 'Object Cache Pro needs Redis 6 or newer for a separate login per application; this server has Redis :version.',
    'redis_no_password' => 'Set a Redis password first: without one, any application could read every other application\'s cache.',
    'redis_failed' => 'Redis refused to create the application\'s login.',
    'object_cache_not_enabled' => 'Object Cache Pro is not enabled on this application.',
];
