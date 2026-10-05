<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version n\'est pas installé.',
    'version_in_use' => 'Node :version est utilisé par :apps. Passez d\'abord ces sites à une autre version de Node (réglage Node.js de chaque site) ou supprimez-les.',
    'version_unknown' => 'Node.js :version n’existe pas. Choisissez une version dans la liste.',
    'version_is_default' => 'C\'est la version par défaut. Choisissez-en une autre d\'abord.',
    'version_runs_panel' => 'Le panneau lui-même fonctionne avec Node :version. Cette version ne peut pas être supprimée.',
    'npm_target_unknown' => 'La liste des versions de npm est inaccessible : impossible de savoir quel npm cette version de Node peut exécuter. Réessayez lorsque le serveur a accès à internet, ou lancez `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Ce serveur héberge des conteneurs et n’exécute aucune application sur l’hôte lui-même : il n’y a donc aucune version de Node.js à gérer. Un conteneur apporte son propre environnement d’exécution.',
    'change_not_node' => 'Seul un site qui fonctionne avec Node.js a une version de Node à changer.',
    'change_legacy_pm2' => 'Ce site tourne encore sous PM2 de l\'ancien panneau. Passez-le d\'abord au gestionnaire de processus du panneau, puis changez sa version de Node.',
    'change_in_progress' => 'Ce site est déjà en cours de passage à Node :version. Attendez la fin.',
    'change_use_endpoint' => 'La version de Node se change avec sa propre action (PUT /applications/{application}/node-version), qui redémarre le site et le vérifie.',
    'change_failed' => [
        'did_not_start' => 'Le site n\'a pas démarré avec Node :target ; il a donc été remis sur Node :current et fonctionne comme avant. Consultez le journal du site pour la raison.',
        'rollback_failed' => 'Le site n\'a pas démarré avec Node :target, et le remettre sur Node :current n\'a pas fonctionné non plus. Consultez le journal du site et redémarrez-le.',
        'unit_write' => 'Le service du site n\'a pas pu être mis à jour pour Node :target. Rien n\'a changé ; il reste sur Node :current.',
        'install_pm2' => 'PM2 n\'a pas pu être installé dans Node :target, dont ce site a besoin pour plusieurs processus. Rien n\'a changé ; il reste sur Node :current.',
        'worker' => 'Le passage à Node :target s\'est arrêté avant la fin. Le site est enregistré sur Node :current ; vérifiez qu\'il fonctionne, puis réessayez.',
    ],
];
