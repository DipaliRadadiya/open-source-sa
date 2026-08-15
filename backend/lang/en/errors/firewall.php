<?php

return [
    'operation_failed' => 'The firewall operation failed on the server.',
    'duplicate' => 'A firewall rule with these settings already exists.',
    'protected_rule' => 'This rule is protected and cannot be removed while the firewall is enabled.',
    'invalid_source' => 'The source must be a valid IP address or CIDR range.',
    'ssh_lockout' => 'This is the only rule allowing SSH on port :port. Removing it would lock you out of this server. Add another rule for that port first, or disable the firewall.',
];
