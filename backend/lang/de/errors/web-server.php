<?php

return [

    // A `map` is only legal inside a listener block, and inventing one
    // would mean guessing an address and port — either a no-op or a
    // hijack of a port something else already owns.
    'ols_listener_missing' => 'OpenLiteSpeed hat keinen Listener ":listener", an den diese Anwendung angehängt werden könnte. Legen Sie zuerst einen in der Webserver-Konfiguration an.',

    'reload_failed' => 'Ihre Änderung wurde gespeichert und hat den Konfigurationstest bestanden, aber der Webserver wurde nicht neu geladen und verwendet noch die vorherigen Einstellungen. Starten Sie den Webserver unter „Dienste“ neu oder prüfen Sie sein Protokoll.',

];
