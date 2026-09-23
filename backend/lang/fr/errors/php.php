<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version n\'est pas installé.',

    // Version lookup, the ini editor and its rollback.
    'unknown_version' => 'PHP :version n\'est pas installé sur ce serveur.',
    'unreadable' => 'Impossible de lire la configuration de PHP :version.',
    'invalid_ini' => 'PHP a rejeté cette configuration : la précédente a été restaurée. Rien n\'a été rechargé.',
    'reload_failed' => 'La modification a été faite, mais PHP :version n\'a pas pu être rechargé : elle n\'est donc pas encore active. Communiquez la référence au support.',
    'operation_failed' => 'La configuration de PHP :version n\'a pas pu être mise à jour.',
    'version_in_use' => 'PHP :version est utilisé par :apps. Modifiez d\'abord ces sites.',
    'version_is_default' => 'C\'est la version par défaut. Choisissez-en une autre d\'abord.',
    'version_runs_panel' => 'Supprimer PHP :version mettrait le panneau hors ligne : c\'est la version sur laquelle il fonctionne.',
    'extension_builtin' => 'L\'extension :extension est compilée dans PHP. Elle ne peut pas être désactivée.',
    'extension_runs_panel' => 'Désactiver :extension mettrait le panneau hors ligne : il a besoin de :modules.',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'Cette action n\'est pas prise en charge par la pile PHP :stack.',

    'ioncube_unsupported_version' => 'ionCube ne publie pas de Loader pour PHP :version.',
    'ioncube_unsupported_architecture' => 'ionCube ne publie pas de Loader pour l\'architecture de ce serveur (:architecture).',
    'ioncube_external' => 'ionCube pour PHP :version a été installé en dehors du panneau (par une version antérieure du panneau ou un paquet système) : le panneau ne le modifiera donc pas. Il est supprimé avec PHP :version.',
    'ioncube_download_failed' => 'Le Loader ionCube n\'a pas pu être téléchargé. Vérifiez l\'accès Internet du serveur et réessayez.',
    'ioncube_invalid_loader' => 'Le fichier téléchargé n\'est pas un Loader ionCube valide pour ce serveur. Rien n\'a été installé.',
    'ioncube_install_failed' => 'Le Loader ionCube n\'a pas pu être installé.',
    'ioncube_discovery_failed' => 'La détection du Loader ionCube n\'a pas pu déterminer en toute sécurité votre installation PHP, donc rien n\'a été modifié.',
    'ioncube_extraction_failed' => 'L\'archive ionCube n\'a pas pu être extraite.',
    'ioncube_removal_failed' => 'Le Loader ionCube précédemment installé n\'a pas pu être supprimé. Consultez la référence pour plus de détails.',
    'ioncube_reload_failed' => 'PHP n\'a pas pu être rechargé. Les modifications ne sont peut-être pas encore actives. Toutes les copies de récupération ont été conservées.',
    'ioncube_rollback_failed' => 'La restauration a échoué ; toutes les copies de récupération ont été conservées. Une récupération manuelle est nécessaire.',
    'ioncube_config_test_failed' => 'La validation de la configuration PHP a échoué. Les fichiers de configuration précédents ont été restaurés et PHP n\'a pas été rechargé.',
];
