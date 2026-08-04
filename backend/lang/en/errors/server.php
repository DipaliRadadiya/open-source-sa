<?php

return [
    // Shown when a server operation lost a race for a system lock and never
    // started. The answer is "try again", not "something is wrong".
    'busy' => 'The server is busy with another system task (a package install or update may be running). Nothing was changed — try again in a moment.',
];
