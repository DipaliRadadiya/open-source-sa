<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'OpenLiteSpeed n\'a pas de listener « :listener » auquel rattacher ce site. Ajoutez-en un dans la configuration du serveur web.',

];
