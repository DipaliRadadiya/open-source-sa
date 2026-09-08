<?php

return [
    'operation_failed' => "L'opération du pare-feu a échoué sur le serveur.",
    'duplicate' => 'Une règle de pare-feu avec ces paramètres existe déjà.',
    'protected_rule' => 'La règle du port :ports est gérée par le panneau : la supprimer pourrait couper l\'accès à ce serveur. Elle ne peut pas être supprimée tant que le pare-feu est activé. Désactivez d\'abord le pare-feu, ou ajoutez votre propre règle à côté.',
    'protected_rule_edit' => 'La règle du port :ports est gérée par le panneau : la modifier pourrait couper l\'accès à ce serveur. Tant que le pare-feu est activé, seule sa description peut être modifiée. Désactivez le pare-feu pour la modifier, ou ajoutez votre propre règle à côté.',
    'invalid_source' => 'La source doit être une adresse IP ou une plage CIDR valide.',
    'ssh_lockout' => "C'est la seule règle autorisant SSH sur le port :port. La supprimer vous couperait l'accès à ce serveur. Ajoutez d'abord une autre règle pour ce port ou désactivez le pare-feu.",
];
