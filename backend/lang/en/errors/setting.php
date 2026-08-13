<?php

return [
    'operation_failed' => 'The settings change failed on the server.',
    'group_unavailable' => 'That settings group is not available on this server.',
    'no_ssh_key' => 'Add an SSH key before disabling password authentication, or you may lock yourself out.',
    'env_not_writable' => 'The panel cannot write its own .env file, so a new Redis password could not be recorded. Fix the file permissions first — otherwise the panel would lose access to Redis.',
    'swap_in_use' => 'Swap is in use and could not be turned off. The server does not have enough free memory to take back what is currently swapped out — free some memory, then try again.',
];
