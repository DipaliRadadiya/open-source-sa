<?php

return [
    'not_installed' => 'fail2ban n\'est pas installé sur ce serveur.',
    'already_installed' => 'fail2ban est déjà installé.',
    'not_running' => 'fail2ban est installé mais ne fonctionne pas.',
    'foreign_jail_local' => 'Le panneau n’a pas écrit :path et ne l’écrasera donc pas. Fail2ban y est déjà configuré à la main ou par un autre panneau — déplacez ou supprimez ce fichier si vous voulez que le panneau gère ces réglages.',
    'jail_not_active' => 'La prison :jail n\'est pas active.',
    'not_banned' => 'Cette adresse IP n\'est pas actuellement bannie.',
    'lockout_risk' => 'Activer la prison SSH peut vous bloquer l\'accès à ce serveur. Ajoutez votre adresse IP à la liste d\'exclusion ou confirmez que vous acceptez le risque.',
    'ip_ignored' => 'Cette adresse IP figure sur la liste d\'exclusion ; le bannissement ne tiendrait pas.',
    'operation_failed' => 'L\'opération fail2ban a échoué.',
    'bantime_too_short' => 'La durée de bannissement doit être d\'au moins 60 secondes, ou -1 pour un bannissement permanent.',
];
