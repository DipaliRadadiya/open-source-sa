<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version ist nicht installiert.',
    'version_in_use' => 'Node :version wird von :apps verwendet. Die Node-Version einer Seite lässt sich noch nicht ändern. Behalten Sie diese Version oder löschen Sie zuerst diese Seiten.',
    'version_unknown' => 'Node.js :version existiert nicht. Wähle eine Version aus der Liste.',
    'version_is_default' => 'Dies ist die Standardversion. Wählen Sie zuerst eine andere.',
    'version_runs_panel' => 'Das Panel selbst läuft mit Node :version. Diese Version kann nicht entfernt werden.',
    'npm_target_unknown' => 'Die npm-Versionsliste war nicht erreichbar, daher lässt sich nicht feststellen, welches npm diese Node-Version ausführen kann. Versuchen Sie es erneut, wenn der Server Internetzugang hat, oder führen Sie `php artisan runtimes:refresh-npm` aus.',
    'not_a_node_server' => 'Dieser Server hostet Container und führt auf dem Host selbst keine Anwendungen aus, daher gibt es keine Node.js-Versionen zu verwalten. Ein Container bringt seine eigene Laufzeitumgebung mit.',
];
