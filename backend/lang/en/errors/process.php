<?php

return [
    'not_found' => 'This process is no longer running.',
    'protected' => 'This process belongs to a protected service and cannot be stopped here.',
    'database' => 'This is a database server. Stopping it would take every site\'s database offline, so it cannot be stopped here. To restart it, use the Services screen.',
    'kernel_thread' => 'Kernel threads cannot be stopped.',
    'self' => 'The panel cannot stop its own process.',
    'kill_failed' => 'The process could not be stopped.',
];
