<?php

return [

    /*
    | Why an install failed, keyed by the `reason` code stored on the
    | install row. Built at read time in the *viewer's* locale — the
    | raw apt or fnm output is never shown, only referenced.
    */

    'install_failed' => [
        'package_not_found' => 'Kein Paket für :version. Prüfe, ob das PHP-Repository konfiguriert und erreichbar ist.',
        'apt_lock' => 'Es läuft bereits ein anderer Paketvorgang. Versuche es gleich noch einmal.',
        'network' => 'Das Paket-Repository war nicht erreichbar. Prüfe die Netzwerkverbindung des Servers.',
        'no_space' => 'Auf dem Server ist kein Speicherplatz mehr frei.',
        'worker' => 'Die Installation wurde unerwartet beendet. Möglicherweise ein Timeout — versuche es erneut.',
        'unknown' => 'Die Installation ist fehlgeschlagen. Nenne dem Support die untenstehende Referenz.',
    ],

    'extension_install_failed' => [
        'package_not_found' => 'Kein Paket für :extension unter PHP :version. Für diese Version existiert es möglicherweise nicht.',
        'apt_lock' => 'Es läuft bereits ein anderer Paketvorgang. Versuche es gleich noch einmal.',
        'network' => 'Das Paket-Repository war nicht erreichbar. Prüfe die Netzwerkverbindung des Servers.',
        'no_space' => 'Auf dem Server ist kein Speicherplatz mehr frei.',
        'worker' => 'Die Installation von :extension wurde unerwartet beendet. Möglicherweise ein Timeout — versuche es erneut.',
        'unknown' => 'Die Installation von :extension ist fehlgeschlagen. Nenne dem Support die untenstehende Referenz.',
    ],

];
