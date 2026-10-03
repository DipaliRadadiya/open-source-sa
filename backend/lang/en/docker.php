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
];
