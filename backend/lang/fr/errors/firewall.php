<?php

return [
    'operation_failed' => "L'opération du pare-feu a échoué sur le serveur.",
    'duplicate' => 'Une règle de pare-feu avec ces paramètres existe déjà.',
    'protected_rule' => 'Cette règle est protégée et ne peut pas être supprimée tant que le pare-feu est activé.',
    'invalid_source' => 'La source doit être une adresse IP ou une plage CIDR valide.',
    'ssh_lockout' => "C'est la seule règle autorisant SSH sur le port :port. La supprimer vous couperait l'accès à ce serveur. Ajoutez d'abord une autre règle pour ce port ou désactivez le pare-feu.",
];
