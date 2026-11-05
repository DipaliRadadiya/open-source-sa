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
    'busy' => ':addon is already being installed or removed. Wait for that to finish.',
    'signing_key_missing' => ':addon cannot be installed: no addon signing key is configured on this server (ADDON_SIGNING_PUBLIC_KEY).',
    'download_failed' => ':addon could not be downloaded, or the file is larger than allowed.',
    'checksum_mismatch' => 'The downloaded :addon file does not match its SHA-256 checksum. Nothing was installed.',
    'signature_invalid' => 'The downloaded :addon file is not signed by Central. Nothing was installed.',
    'not_executable' => 'The downloaded :addon file is not a Linux program. Nothing was installed.',
    'disk_low' => ':addon needs more than 5 GB free for its data; this server has :message GB free.',
    'install_failed' => ':addon could not be installed (step :message). The changes this install made were undone.',
    'remove_failed' => ':addon could not be fully removed (step :message).',
    'stranded' => 'This run stopped without finishing.',
    'url_not_central' => 'The download address must use https on Central\'s own host.',
    'signature_malformed' => 'The signature must be a base64-encoded Ed25519 signature.',
];
