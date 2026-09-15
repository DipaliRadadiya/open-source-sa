<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version n\'est pas installé.',
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
    'ioncube_download_failed' => 'Le Loader ionCube n\'a pas pu être téléchargé. Vérifiez l\'accès Internet du serveur et réessayez.',
    'ioncube_invalid_loader' => 'Le fichier téléchargé n\'est pas un Loader ionCube valide pour ce serveur. Rien n\'a été installé.',
    'ioncube_install_failed' => 'Le Loader ionCube n\'a pas pu être installé.',
    'ioncube_config_test_failed' => 'PHP a refusé de démarrer avec le Loader ionCube, il a donc été retiré. Vos sites n\'ont pas été affectés.',
];
