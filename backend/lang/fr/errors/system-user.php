<?php

return [
    'create_failed' => "La création de l'utilisateur système sur le serveur a échoué.",
    'delete_failed' => "La suppression de l'utilisateur système sur le serveur a échoué.",
    'has_applications' => 'Cet utilisateur système possède encore une ou plusieurs applications et ne peut pas être supprimé.',
    'reserved_username' => "Ce nom d'utilisateur est réservé et ne peut pas être utilisé.",
    'duplicate_public_key' => 'Cette clé SSH a déjà été ajoutée.',
    'invalid_public_key' => "La valeur fournie n'est pas une clé publique SSH valide.",
    'dsa_public_key' => "C'est une clé DSA. Ubuntu n'accepte plus les clés DSA pour la connexion SSH, elle ne fonctionnerait donc jamais. Utilisez une clé Ed25519 (recommandé) ou RSA 4096.",
    'weak_rsa_public_key' => 'Cette clé RSA est trop courte. Utilisez au moins :bits bits — RSA 4096 ou une clé Ed25519 est recommandé.',
    'ssh_key_exists' => ':username a déjà cette clé SSH, sous le nom « :name ».',
    'password_failed' => "Échec de la définition du mot de passe de l'utilisateur système.",
    'sudo_failed' => "Échec de la mise à jour de l'accès sudo de l'utilisateur système.",
    'shell_failed' => "Échec du changement de shell de l'utilisateur système.",
    'ssh_failed' => "Échec de la mise à jour de l'accès SSH de l'utilisateur système.",

    // The panel must not record access the server will not grant: sshd
    // authenticates, then a non-login shell exits and the session closes.
    'ssh_needs_login_shell' => 'L\'accès SSH nécessite un shell permettant à l\'utilisateur de se connecter. Le shell de cet utilisateur refuse la connexion : SSH se connecterait puis se déconnecterait aussitôt. Choisissez d\'abord un shell de connexion.',
    'shell_needs_ssh_off' => 'Cet utilisateur a un accès SSH et le shell choisi refuse la connexion — SSH se connecterait puis se déconnecterait aussitôt. Désactivez d\'abord l\'accès SSH, ou choisissez un shell de connexion.',

    'password_control_characters' => 'Le mot de passe ne peut pas contenir de sauts de ligne ni d\'autres caractères de contrôle.',
    'shell_not_installed' => 'Le shell :shell n\'est pas installé sur ce serveur. Installez-le d\'abord ou choisissez un autre shell.',
    'username_taken_on_server' => 'Un utilisateur ou un groupe portant ce nom existe déjà sur le serveur. Choisissez un autre nom d\'utilisateur.',
    'has_processes' => ':username est toujours connecté ou exécute quelque chose (par exemple une session SSH ouverte ou une tâche en cours). Déconnectez-le ou attendez la fin, ou supprimez-le à nouveau avec « Terminer les sessions » activé, qui les termine d’abord.',

    // Container login (RC-28).
    'container_login_failed' => 'La connexion au conteneur n\'a pas pu être activée pour l\'utilisateur système.',
    'container_login_unsupported' => 'La configuration SSH de ce serveur n\'applique pas la connexion au conteneur (son sshd_config n\'inclut pas sshd_config.d), elle n\'a donc pas été activée.',
    'container_login_no_owner' => 'Cette application n\'a pas d\'utilisateur système.',
    'container_login_confirm' => '{1} :username possède aussi :count autre application. Son SSH et son SFTP n\'ouvriront plus que ce conteneur. Confirmez pour continuer.|[2,*] :username possède aussi :count autres applications. Son SSH et son SFTP n\'ouvriront plus que ce conteneur. Confirmez pour continuer.',
    'container_login_needs_key' => 'La connexion par mot de passe est désactivée sur ce serveur, :username a donc besoin d\'une clé SSH. Ajoutez-en une pour activer la connexion au conteneur.',
];
