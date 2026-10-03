<?php

return [
    'registry_status' => [
        'connected' => 'Connecté',
        'never_tested' => 'Pas encore testé',
        'failed' => 'Dernier test échoué',
    ],

    'registry_test_error' => [
        'invalid_credentials' => 'Le registre a refusé ce nom d’utilisateur et ce jeton.',
        'unreachable' => 'Le registre est injoignable depuis ce serveur. Vérifiez l’adresse et son accessibilité en HTTPS.',
        'unknown' => 'Le registre a refusé la connexion sans dire pourquoi. Le journal des opérations serveur contient la réponse de Docker.',
    ],
];
