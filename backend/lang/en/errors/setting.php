<?php

return [
    'operation_failed' => 'The settings change failed on the server.',
    'group_unavailable' => 'That settings group is not available on this server.',
    'no_ssh_key' => 'Add an SSH key before disabling password authentication, or you may lock yourself out.',
    'redis_credential_unusable' => 'The panel cannot reach Redis with the password it has stored, so it cannot change it. Redis is running but rejecting the panel\'s credential — correct REDIS_PASSWORD in the panel\'s .env to the password Redis actually requires, then try again.',
    'env_not_writable' => 'The panel cannot write its own .env file, so a new Redis password could not be recorded. Fix the file permissions first — otherwise the panel would lose access to Redis.',
    'swap_in_use' => 'Swap is in use and could not be turned off. The server does not have enough free memory to take back what is currently swapped out — free some memory, then try again.',
    'database_unreachable' => 'The panel cannot connect to the database server, so this change was not made. {engine} is installed and running, but the panel\'s stored credentials are rejected — set them in Databases, then try again.',
    'database_absent' => 'No MySQL or MariaDB server is installed on this machine.',
];
