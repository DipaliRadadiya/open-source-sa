<?php

return [
    'primary_domain_not_removable' => 'Le domaine principal ne peut pas être supprimé. Définissez d\'abord un autre domaine comme principal.',
    'unsupported_web_server' => 'Le panneau ne peut pas écrire la configuration du site pour :web_server.',
    'no_web_server' => 'aucun serveur web détecté',
    'provision_failed' => 'La configuration du site a échoué à l\'étape « :step ».',
    'not_a_git_application' => 'Cette application n\'est pas un déploiement git : il n\'y a rien à récupérer.',
    'no_database_engine' => 'Aucun moteur de base de données disponible. Installez et configurez MySQL ou MariaDB avant de créer cette application.',
    'no_process' => '« :name » n\'exécute pas de processus propre.',
    'process_failed' => 'Impossible de :action l\'application. Communiquez la référence au support.',
    'no_port_available' => 'Aucun port libre entre :from et :to. Libérez-en un ou élargissez la plage.',

    'webhook_not_a_git_application' => 'Le déploiement automatique n\'est disponible que pour les applications déployées depuis un dépôt git.',

    'already_disabled' => 'Cette application est déjà désactivée.',
    'not_disabled' => 'Cette application n\'est pas désactivée.',
    'availability_failed' => 'La modification de la disponibilité de l\'application a échoué sur le serveur.',
    'basic_auth_failed' => 'La modification de la protection par mot de passe a échoué sur le serveur.',
    'bot_blocker_failed' => 'La modification de la politique du bloqueur de robots IA a échoué sur le serveur.',
    'bot_agent_invalid' => 'Saisissez un seul nom de robot, comme GPTBot ou SemrushBot — lettres, chiffres, points et tirets uniquement.',
    'bot_agent_too_broad' => 'C\'est trop général — cela bloquerait aussi des moteurs de recherche comme Google et Bing. Utilisez le nom complet du robot.',
    'bot_agent_search_engine' => 'C\'est un moteur de recherche, pas un robot d\'IA. Le bloquer retirerait votre site des résultats de recherche.',
    'web_root_failed' => 'La modification de la racine web a échoué sur le serveur.',
    'web_root_not_found' => 'Le répertoire racine web est introuvable sur le serveur. Vérifiez la racine web dans les paramètres de l\'application et reprovisionnez-la si elle n\'a jamais été créée.',
    'waf_failed' => 'La modification des paramètres du pare-feu a échoué sur le serveur.',
    'staging_failed' => 'L\'opération de staging a échoué sur le serveur.',
    'clone_failed' => 'L\'opération de clonage a échoué sur le serveur.',
    'fail2ban_failed' => 'L\'opération fail2ban a échoué sur le serveur.',

    'permissions_fix_failed' => 'La réinitialisation des permissions de fichiers a échoué sur le serveur.',

    'unsafe_path' => 'Ce chemin n\'est pas autorisé.',
    'file_too_large' => 'Ce fichier est trop volumineux pour être ouvert ici. Utilisez SFTP pour les fichiers volumineux.',
    'file_not_text' => 'Ce fichier ne semble pas être du texte et ne peut pas être ouvert ici.',
    'file_operation_failed' => 'L\'opération sur le fichier a échoué sur le serveur.',

    'file_not_archive' => 'Seules les archives .zip et .tar.gz peuvent être extraites ici.',
    'archive_unreadable' => 'Cette archive n\'a pas pu être lue. Elle est peut-être corrompue.',
    'archive_empty' => 'Cette archive ne contient rien.',
    'archive_too_many_entries' => 'Cette archive contient trop de fichiers pour être extraite ici.',
    'archive_too_large' => 'Cette archive serait trop volumineuse une fois extraite.',
    'archive_has_symlink' => 'Cette archive contient un lien symbolique, ce qui n\'est pas autorisé.',
    'archive_unsafe_entry' => 'Cette archive contient un chemin de fichier qui n\'est pas autorisé.',

    'path_exists' => 'Quelque chose existe déjà à ce chemin.',
    'cannot_delete_root' => 'Le dossier racine du site ne peut pas être supprimé.',
    'target_not_zip' => 'Le nom de la nouvelle archive doit se terminer par .zip.',
    'unknown_backup' => 'Ce n\'est pas une sauvegarde connue de ce fichier.',

];
