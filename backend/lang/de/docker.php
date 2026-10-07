<?php

return [
    'registry_status' => [
        'connected' => 'Verbunden',
        'never_tested' => 'Noch nicht getestet',
        'failed' => 'Letzter Test fehlgeschlagen',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'Die Registry hat diesen Benutzernamen und dieses Token abgelehnt.',
        'unreachable' => 'Die Registry ist von diesem Server nicht erreichbar. Prüfen Sie die Adresse und ob sie über HTTPS erreichbar ist.',
        'unknown' => 'Die Registry hat die Verbindung abgelehnt und keinen Grund genannt. Die Antwort von Docker steht im Server-Ops-Log.',
    ],

    // Image discovery (DS-02): search, versions and inspect on the create form.
    'image' => [
        'invalid_reference' => 'Das ist kein Image-Name, den Docker akzeptiert. Verwende einen Namen wie nginx, usememos/memos oder ghcr.io/owner/app, optional gefolgt von :version. Repository-Namen sind kleingeschrieben.',
        'not_found' => 'Kein Image namens :image gefunden. Prüfe die Schreibweise – oder wähle bei einem privaten Image die Registry-Zugangsdaten, die es lesen können.',
        'tag_not_found' => 'Das Image :image hat keine Version :tag. Wähle eine aus der Versionsliste.',
        'credential_rejected' => 'Die gewählten Registry-Zugangsdaten können :image nicht lesen. Prüfe, ob das Image existiert und ob das Token es pullen darf.',
        'registry_unreachable' => 'Die Registry :registry ist von diesem Server aus nicht erreichbar, daher konnte das Image nicht geprüft werden. Du kannst den Port trotzdem selbst eingeben.',
        'rate_limited' => 'Docker Hub begrenzt, wie oft dieser Server anfragen darf. Versuche es in ein paar Minuten erneut oder gib den Port selbst ein.',
        'blocked_host' => 'Das Panel verbindet sich nicht mit dieser Registry-Adresse. Loopback- und Link-Local-Adressen werden abgelehnt.',
        'warning_required_env' => 'Dieses Image braucht diese Einstellungen, bevor es startet: :keys.',
        'warning_empty_env' => 'Diese Einstellungen sind im Image leer. Fülle sie nur aus, wenn die Dokumentation des Images das verlangt: :keys.',
        'warning_no_build' => 'Dieses Image hat keinen Build für :architecture und läuft daher nicht auf diesem Server.',
        'warning_large' => 'Dieses Image ist :size groß. Der erste Start dauert eine Weile.',
        'warning_no_port' => 'Dieses Image gibt nicht an, auf welchem Port es lauscht. Gib den Port aus der Dokumentation des Images ein.',
        'warning_several_ports' => 'Dieses Image lauscht auf mehreren Ports (:ports). Für die Website wurde Port :port gewählt; ändere ihn, falls die Dokumentation etwas anderes sagt.',
    ],
];
