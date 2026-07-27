<?php

return [
    'apt_cache' => ['label' => 'Cache des paquets', 'description' => 'Fichiers de paquets .deb téléchargés qui ne sont plus nécessaires.'],
    'apt_orphans' => ['label' => 'Paquets inutilisés', 'description' => 'Paquets installés automatiquement et anciens noyaux qui ne sont plus nécessaires.'],
    'journal' => ['label' => 'Journal système', 'description' => 'Entrées du journal systemd plus anciennes que la période de rétention.'],
    'rotated_logs' => ['label' => 'Journaux archivés', 'description' => 'Anciennes archives de journaux compressées et pivotées sous /var/log.'],
    'service_logs' => ['label' => 'Journaux des services', 'description' => 'Vide les fichiers journaux actuels des services en cours (conservés, non supprimés).'],
    'tmp' => ['label' => 'Fichiers temporaires', 'description' => 'Anciens fichiers dans /tmp et /var/tmp.'],
];
