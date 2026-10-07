<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Registry credentials
    |--------------------------------------------------------------------------
    |
    | Its own file rather than `errors/docker.php`, because a connection verdict
    | is not an error — `never_tested` in particular is the panel describing
    | itself, and filing it under errors would put "we have not asked yet" in the
    | same namespace as "this failed".
    |
    */

    'registry_status' => [
        'connected' => 'Connected',
        // "Not yet tested" rather than "Unknown": the panel has not asked, which
        // is a statement about the panel and not a fault in the credential.
        'never_tested' => 'Not yet tested',
        'failed' => 'Last test failed',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'The registry refused this username and token.',
        'unreachable' => 'The registry could not be reached from this server. Check the address, and whether it is reachable over HTTPS.',
        // Reached when Docker said something neither category covers. Named
        // rather than guessed at, because a wrong category sends somebody to
        // change the thing that was never broken.
        'unknown' => 'The registry refused the connection and did not say why. The server-ops log holds Docker\'s own answer.',
    ],

    // Image discovery (DS-02): search, versions and inspect on the create form.
    'image' => [
        'invalid_reference' => 'That is not an image name Docker accepts. Use a name like nginx, usememos/memos or ghcr.io/owner/app, optionally followed by :version. Repository names are lowercase.',
        'not_found' => 'No image called :image was found. Check the spelling — or, if it is private, choose the registry credential that can read it.',
        'tag_not_found' => 'The image :image has no version :tag. Pick one from the version list.',
        'credential_rejected' => 'The chosen registry credential cannot read :image. Check that the image exists and that the token can pull it.',
        'registry_unreachable' => 'The registry :registry could not be reached from this server, so the image could not be checked. You can still enter the port yourself.',
        'rate_limited' => 'Docker Hub is limiting how often this server may ask. Try again in a few minutes, or enter the port yourself.',
        'blocked_host' => 'The panel does not connect to that registry address. Loopback and link-local addresses are refused.',
        'warning_required_env' => 'This image needs these settings before it will start: :keys.',
        'warning_empty_env' => 'These settings are empty in the image. Fill them in only if the image\'s documentation asks for them: :keys.',
        'warning_no_build' => 'This image has no :architecture build, so it will not run on this server.',
        'warning_large' => 'This image is :size to download. The first start will take a while.',
        'warning_no_port' => 'This image does not say which port it listens on. Enter the port from the image\'s documentation.',
        'warning_several_ports' => 'This image listens on several ports (:ports). Port :port was chosen for the website; change it if the documentation says otherwise.',
    ],
];
