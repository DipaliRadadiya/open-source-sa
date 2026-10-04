<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version n\'est pas installé.',
    'version_in_use' => 'Node :version est utilisé par :apps. La version de Node d\'un site ne peut pas encore être modifiée : conservez cette version ou supprimez d\'abord ces sites.',
    'version_unknown' => 'Node.js :version n’existe pas. Choisissez une version dans la liste.',
    'version_is_default' => 'C\'est la version par défaut. Choisissez-en une autre d\'abord.',
    'version_runs_panel' => 'Le panneau lui-même fonctionne avec Node :version. Cette version ne peut pas être supprimée.',
    'npm_target_unknown' => 'La liste des versions de npm est inaccessible : impossible de savoir quel npm cette version de Node peut exécuter. Réessayez lorsque le serveur a accès à internet, ou lancez `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Ce serveur héberge des conteneurs et n’exécute aucune application sur l’hôte lui-même : il n’y a donc aucune version de Node.js à gérer. Un conteneur apporte son propre environnement d’exécution.',
];
