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
];
