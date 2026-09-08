<?php

return [
    'operation_failed' => 'The firewall operation failed on the server.',
    'duplicate' => 'A firewall rule with these settings already exists.',
    'protected_rule' => 'The rule for port :ports is managed by the panel — removing it could cut off access to this server. It cannot be removed while the firewall is on. Turn the firewall off first, or add your own rule alongside it.',
    'protected_rule_edit' => 'The rule for port :ports is managed by the panel — changing it could cut off access to this server. While the firewall is on, only its description can be changed. Turn the firewall off to edit it, or add your own rule alongside it.',
    'invalid_source' => 'The source must be a valid IP address or CIDR range.',
    'ssh_lockout' => 'This is the only rule allowing SSH on port :port. Removing it would lock you out of this server. Add another rule for that port first, or disable the firewall.',
];
