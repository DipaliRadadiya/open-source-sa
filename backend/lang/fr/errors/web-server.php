<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'OpenLiteSpeed n\'a pas de listener « :listener » auquel rattacher cette application. Ajoutez-en un dans la configuration du serveur web.',

    'reload_failed' => 'Votre modification a été enregistrée et a passé le test de configuration, mais le serveur web ne s’est pas rechargé : il utilise encore les réglages précédents. Redémarrez-le depuis Services ou consultez son journal.',

];
