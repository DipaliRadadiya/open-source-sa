<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'OpenLiteSpeed no tiene un listener \":listener\" al que asociar este sitio. Añade uno en la configuración del servidor web primero.',

];
