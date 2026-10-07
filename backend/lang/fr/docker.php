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

    // Image discovery (DS-02): search, versions and inspect on the create form.
    'image' => [
        'invalid_reference' => 'Ce n\'est pas un nom d\'image accepté par Docker. Utilisez un nom comme nginx, usememos/memos ou ghcr.io/owner/app, éventuellement suivi de :version. Les noms de dépôt sont en minuscules.',
        'not_found' => 'Aucune image nommée :image n\'a été trouvée. Vérifiez l\'orthographe — ou, si elle est privée, choisissez l\'identifiant de registre qui peut la lire.',
        'tag_not_found' => 'L\'image :image n\'a pas de version :tag. Choisissez-en une dans la liste des versions.',
        'credential_rejected' => 'L\'identifiant de registre choisi ne peut pas lire :image. Vérifiez que l\'image existe et que le jeton peut la télécharger.',
        'registry_unreachable' => 'Le registre :registry est injoignable depuis ce serveur, l\'image n\'a donc pas pu être vérifiée. Vous pouvez toujours saisir le port vous-même.',
        'rate_limited' => 'Docker Hub limite la fréquence des requêtes de ce serveur. Réessayez dans quelques minutes ou saisissez le port vous-même.',
        'blocked_host' => 'Le panneau ne se connecte pas à cette adresse de registre. Les adresses de bouclage et link-local sont refusées.',
        'warning_required_env' => 'Cette image a besoin de ces réglages pour démarrer : :keys.',
        'warning_empty_env' => 'Ces réglages sont vides dans l\'image. Ne les remplissez que si la documentation de l\'image le demande : :keys.',
        'warning_no_build' => 'Cette image n\'a pas de build :architecture, elle ne fonctionnera donc pas sur ce serveur.',
        'warning_large' => 'Cette image pèse :size à télécharger. Le premier démarrage prendra un moment.',
        'warning_no_port' => 'Cette image n\'indique pas le port sur lequel elle écoute. Saisissez le port donné par sa documentation.',
        'warning_several_ports' => 'Cette image écoute sur plusieurs ports (:ports). Le port :port a été choisi pour le site ; changez-le si la documentation indique autre chose.',
    ],
];
