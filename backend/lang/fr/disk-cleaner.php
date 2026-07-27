<?php

return [
    'apt_cache' => ['label' => 'Cache des paquets', 'description' => 'Fichiers de paquets .deb téléchargés qui ne sont plus nécessaires.', 'note' => 'Supprime uniquement les téléchargements en cache sous /var/cache/apt/archives ; les paquets installés continuent de fonctionner.'],
    'apt_orphans' => ['label' => 'Paquets inutilisés', 'description' => 'Paquets installés automatiquement et anciens noyaux qui ne sont plus nécessaires.', 'note' => "Supprime les paquets dont plus rien ne dépend et les noyaux obsolètes ; le noyau en cours d'exécution est conservé."],
    'journal' => ['label' => 'Journal système', 'description' => 'Entrées du journal systemd plus anciennes que la période de rétention.', 'note' => "Élague l'ancien historique du journal au-delà de la période de rétention ; les entrées récentes sont conservées."],
    'rotated_logs' => ['label' => 'Journaux archivés', 'description' => 'Anciennes archives de journaux compressées et pivotées sous /var/log.', 'note' => 'Supprime les archives déjà pivotées (.gz / .1 / .old) sous /var/log ; les journaux actuels ne sont pas touchés.'],
    'service_logs' => ['label' => 'Journaux des services', 'description' => 'Vide les fichiers journaux actuels des services en cours (conservés, non supprimés).', 'note' => 'Vide les fichiers journaux actuels (tronqués à 0 octet) ; les services continuent d’y écrire, rien n’est supprimé.'],
    'tmp' => ['label' => 'Fichiers temporaires', 'description' => 'Anciens fichiers dans /tmp et /var/tmp.', 'note' => 'Supprime les fichiers de /tmp et /var/tmp plus anciens que la période de rétention.'],
];
