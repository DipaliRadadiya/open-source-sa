<?php

return [
    'not_installed' => 'fail2ban n\'est pas installé sur ce serveur.',
    'already_installed' => 'fail2ban est déjà installé.',
    'already_installing' => 'fail2ban est déjà en cours d’installation. Attendez la fin de cette installation.',
    'not_running' => 'fail2ban est installé mais ne fonctionne pas.',
    'foreign_jail_local' => 'Le panneau n’a pas écrit :path et ne l’écrasera donc pas. Fail2ban y est déjà configuré à la main ou par un autre panneau — déplacez ou supprimez ce fichier si vous voulez que le panneau gère ces réglages.',
    'jail_not_active' => 'La prison :jail n\'est pas active.',
    'not_banned' => 'Cette adresse IP n\'est pas actuellement bannie.',
    'lockout_risk' => 'Activer la prison SSH peut vous bloquer l\'accès à ce serveur. Ajoutez votre adresse IP à la liste d\'exclusion ou confirmez que vous acceptez le risque.',
    'ip_ignored' => 'Cette adresse IP figure sur votre liste d\'exclusion. Retirez-l\'en d\'abord si vous voulez vraiment la bannir.',
    'ip_own_address' => 'Il s\'agit de l\'adresse propre de ce serveur. La bloquer peut couper le panneau de sa base de données et le rendre inaccessible ; c\'est donc refusé.',
    'ip_your_address' => 'C\'est l\'adresse depuis laquelle vous êtes connecté. La bannir vous couperait l\'accès à ce serveur, et avec la prison recidive à ce panneau aussi ; elle ne peut donc pas être bannie d\'ici.',
    'operation_failed' => 'L\'opération fail2ban a échoué.',
    'bantime_too_short' => 'La durée de bannissement doit être d\'au moins 60 secondes, ou -1 pour un bannissement permanent.',
    // FS-C45: the application's own ban list, ban and unban.
    'app_jail_not_enabled' => 'Fail2ban n’exécute aucune prison pour cette application. Activez d’abord sa protection fail2ban.',
];
