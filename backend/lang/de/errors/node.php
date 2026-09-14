<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version ist nicht installiert.',
    'version_in_use' => 'Node :version wird von :apps verwendet. Ändern Sie zuerst diese Seiten.',
    'version_is_default' => 'Dies ist die Standardversion. Wählen Sie zuerst eine andere.',
    'npm_target_unknown' => 'Die npm-Versionsliste war nicht erreichbar, daher lässt sich nicht feststellen, welches npm diese Node-Version ausführen kann. Versuchen Sie es erneut, wenn der Server Internetzugang hat, oder führen Sie `php artisan runtimes:refresh-npm` aus.',
];
