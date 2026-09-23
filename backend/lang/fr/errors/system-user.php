<?php

return [
    'create_failed' => "La création de l'utilisateur système sur le serveur a échoué.",
    'delete_failed' => "La suppression de l'utilisateur système sur le serveur a échoué.",
    'has_applications' => 'Cet utilisateur système possède encore une ou plusieurs applications et ne peut pas être supprimé.',
    'reserved_username' => "Ce nom d'utilisateur est réservé et ne peut pas être utilisé.",
    'duplicate_public_key' => 'Cette clé SSH a déjà été ajoutée.',
    'invalid_public_key' => "La valeur fournie n'est pas une clé publique SSH valide.",
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
    'has_processes' => 'Cet utilisateur a encore des processus en cours (par exemple une session SSH ouverte ou une tâche en cours). Arrêtez-les, puis réessayez.',
];
