<?php

return [
    'not_found' => 'This process is no longer running.',
    'protected' => 'This process belongs to a protected service and cannot be stopped here.',
    'database' => 'This is a database server. Stopping it would take every site\'s database offline, so it cannot be stopped here. To restart it, use the Services screen.',
    'kernel_thread' => 'Kernel threads cannot be stopped.',
    'self' => 'The panel cannot stop its own process.',
    'kill_failed' => 'The process could not be stopped.',
    'still_running' => 'The process is still running. It may still be shutting down, or it may be ignoring the request. Use Force stop to end it now.',
    'still_running_after_kill' => 'The process is still running after Force stop. It is probably stuck waiting on a disk or network share, and no signal can end it until that wait finishes.',
];
