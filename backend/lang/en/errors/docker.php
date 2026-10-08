<?php

return [
    'not_a_docker_server' => 'This server does not host containers, so it has no Docker networks or volumes.',
    'invalid_name' => 'A name may use letters, numbers, dots, dashes and underscores, and must start with a letter or number.',
    'network_exists' => 'A network called :name already exists.',
    'volume_exists' => 'A volume called :name already exists.',
    'network_built_in' => ':name is one of Docker\'s own networks. Docker recreates it on restart, and removing it would break every container on this server.',
    'network_in_use' => 'The network :name still has containers on it: :containers. Stop or detach them first.',
    'network_used_by_sites' => 'These sites are set to join the network :name: :sites. Change their network first — removing it now would stop them starting.',
    'volume_in_use' => 'The volume :name is still used by containers (:count). Stop them first — removing a volume in use deletes data something is still writing to.',
    'volume_in_use_by' => 'The volume :name is still used by :containers. Stop them first — removing a volume in use deletes data something is still writing to.',
    'volume_used_by_sites' => 'These sites mount the volume :name: :sites. Remove the mount from them first — deleting it now destroys the data they keep in it.',
    'network_create_failed' => 'The network could not be created. Reference :reference.',
    'network_remove_failed' => 'The network could not be removed. Reference :reference.',
    'volume_create_failed' => 'The volume could not be created. Reference :reference.',
    'volume_remove_failed' => 'The volume could not be removed. Reference :reference.',
    'registry_deleted' => 'The registry credential was deleted. Sites that used it will pull anonymously from now on.',
    'registry_credential_unwritable' => 'The registry credential could not be written to disk, so Docker was never asked. Reference :reference.',
    'database_start_failed' => 'The database could not be started (:step). Nothing was left behind — no row, no container and no port held.',
    'database_version_unknown' => 'That engine has no version :version. Pick one the panel offers for :engine.',
    'database_deleted' => 'The database was removed.',
];
