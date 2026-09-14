<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version n\'est pas installé.',
    'version_in_use' => 'Node :version est utilisé par :apps. Modifiez d\'abord ces sites.',
    'version_is_default' => 'C\'est la version par défaut. Choisissez-en une autre d\'abord.',
    'npm_target_unknown' => 'La liste des versions de npm est inaccessible : impossible de savoir quel npm cette version de Node peut exécuter. Réessayez lorsque le serveur a accès à internet, ou lancez `php artisan runtimes:refresh-npm`.',
];
