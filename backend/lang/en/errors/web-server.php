<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'OpenLiteSpeed has no ":listener" listener to attach this application to. Add one in the web server configuration first.',

    'reload_failed' => 'Your change was saved and passed the configuration test, but the web server did not reload, so it is still serving the previous settings. Restart the web server from Services, or check its log.',

];
