<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version n\'est pas installé.',
    'version_in_use' => 'Node :version est utilisé par :apps. Passez d\'abord ces applications à une autre version de Node (réglage Node.js de chaque application) ou supprimez-les.',
    'version_unknown' => 'Node.js :version n’existe pas. Choisissez une version dans la liste.',
    'version_is_default' => 'C\'est la version par défaut. Choisissez-en une autre d\'abord.',
    'version_runs_panel' => 'Le panneau lui-même fonctionne avec Node :version. Cette version ne peut pas être supprimée.',
    'npm_target_unknown' => 'La liste des versions de npm est inaccessible : impossible de savoir quel npm cette version de Node peut exécuter. Réessayez lorsque le serveur a accès à internet, ou lancez `php artisan runtimes:refresh-npm`.',
    'not_a_node_server' => 'Ce serveur héberge des conteneurs et n’exécute aucune application sur l’hôte lui-même : il n’y a donc aucune version de Node.js à gérer. Un conteneur apporte son propre environnement d’exécution.',
    'change_not_node' => 'Seule une application qui fonctionne avec Node.js a une version de Node à changer.',
    'change_legacy_pm2' => 'Cette application tourne encore sous PM2 de l\'ancien panneau. Passez-la d\'abord au gestionnaire de processus du panneau, puis changez sa version de Node.',
    'change_in_progress' => 'Cette application est déjà en cours de passage à Node :version. Attendez la fin.',
    'change_use_endpoint' => 'La version de Node se change avec sa propre action (PUT /applications/{application}/node-version), qui redémarre l’application et la vérifie.',
    'change_failed' => [
        'did_not_start' => 'L’application n\'a pas démarré avec Node :target ; elle a donc été remise sur Node :current et fonctionne comme avant. Consultez le journal de l’application pour la raison.',
        'rollback_failed' => 'L’application n\'a pas démarré avec Node :target, et le remettre sur Node :current n\'a pas fonctionné non plus. Consultez le journal de l’application et redémarrez-la.',
        'unit_write' => 'Le service de l’application n\'a pas pu être mis à jour pour Node :target. Rien n\'a changé ; elle reste sur Node :current.',
        'install_pm2' => 'PM2 n\'a pas pu être installé dans Node :target, dont cette application a besoin pour plusieurs processus. Rien n\'a changé ; elle reste sur Node :current.',
        'worker' => 'Le passage à Node :target s\'est arrêté avant la fin. L’application est enregistrée sur Node :current ; vérifiez qu\'elle fonctionne, puis réessayez.',
    ],
    'remove_failed' => 'Node :version n’a pas pu être supprimé. Communiquez la référence ci-dessous au support.',
    'remove_failed_said' => 'Node :version n’a pas pu être supprimé. fnm a indiqué : « :output »',
    'install_in_progress' => 'Node :version est déjà en cours d’installation. Attendez la fin de cette installation.',
];
