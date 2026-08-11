<?php

return [
    'issues' => [
        'certificate' => [
            'expired' => 'Le certificat SSL a expiré.',
            'expiring' => 'Le certificat SSL expire dans :days jours.',
        ],
        'worker' => [
            'stopped' => 'Le processus de l\'application est arrêté.',
        ],
        'dns' => [
            'drift' => 'Le DNS a changé — l\'IP du domaine ne correspond plus à la valeur enregistrée.',
            'unresolved' => 'Le DNS du domaine ne peut pas être résolu pour le moment.',
        ],
        'php_eol' => 'PHP :version est en fin de vie et ne reçoit plus de mises à jour de sécurité.',
        'disk' => 'L\'utilisation du disque est à :percent%.',
        'deploy_failed' => 'Le dernier déploiement a échoué à l\'étape :step.',
    ],
];
