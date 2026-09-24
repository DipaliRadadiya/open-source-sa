<?php

return [
    'not_a_docker_server' => 'This server does not host containers, so it has no Docker networks or volumes.',
    'invalid_name' => 'A name may use letters, numbers, dots, dashes and underscores, and must start with a letter or number.',
    'network_exists' => 'A network called :name already exists.',
    'volume_exists' => 'A volume called :name already exists.',
    'network_built_in' => ':name is one of Docker\'s own networks. Docker recreates it on restart, and removing it would break every container on this server.',
    'network_in_use' => 'The network :name still has containers on it: :containers. Stop or detach them first.',
    'volume_in_use' => 'The volume :name is still used by :count container(s). Stop them first — removing a volume in use deletes data something is still writing to.',
    'network_create_failed' => 'The network could not be created. Reference :reference.',
    'network_remove_failed' => 'The network could not be removed. Reference :reference.',
    'volume_create_failed' => 'The volume could not be created. Reference :reference.',
    'volume_remove_failed' => 'The volume could not be removed. Reference :reference.',
];
