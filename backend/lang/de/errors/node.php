<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version ist nicht installiert.',
    'version_in_use' => 'Node :version wird von :apps verwendet. Stellen Sie diese Anwendungen zuerst auf eine andere Node-Version um (Node.js-Einstellung der Anwendung) oder löschen Sie sie.',
    'version_unknown' => 'Node.js :version existiert nicht. Wählen Sie eine Version aus der Liste.',
    'version_is_default' => 'Dies ist die Standardversion. Wählen Sie zuerst eine andere.',
    'version_runs_panel' => 'Das Panel selbst läuft mit Node :version. Diese Version kann nicht entfernt werden.',
    'npm_target_unknown' => 'Die npm-Versionsliste war nicht erreichbar, daher lässt sich nicht feststellen, welches npm diese Node-Version ausführen kann. Versuchen Sie es erneut, wenn der Server Internetzugang hat, oder führen Sie `php artisan runtimes:refresh-npm` aus.',
    'not_a_node_server' => 'Dieser Server hostet Container und führt auf dem Host selbst keine Anwendungen aus, daher gibt es keine Node.js-Versionen zu verwalten. Ein Container bringt seine eigene Laufzeitumgebung mit.',
    'change_not_node' => 'Nur eine Website, die mit Node.js läuft, hat eine Node-Version, die man ändern kann.',
    'change_legacy_pm2' => 'Diese Anwendung läuft noch unter PM2 aus dem vorherigen Panel. Stellen Sie sie zuerst auf den Prozessmanager des Panels um und ändern Sie dann ihre Node-Version.',
    'change_in_progress' => 'Diese Anwendung wird bereits auf Node :version umgestellt. Warten Sie, bis das abgeschlossen ist.',
    'change_use_endpoint' => 'Die Node-Version wird mit einer eigenen Aktion geändert (PUT /applications/{application}/node-version), die die Website neu startet und prüft.',
    'change_failed' => [
        'did_not_start' => 'Die Anwendung ist mit Node :target nicht gestartet, daher wurde sie auf Node :current zurückgestellt und läuft wie zuvor. Den Grund finden Sie im Log der Anwendung.',
        'rollback_failed' => 'Die Anwendung ist mit Node :target nicht gestartet, und das Zurückstellen auf Node :current hat auch nicht funktioniert. Prüfen Sie das Log der Anwendung und starten Sie sie neu.',
        'unit_write' => 'Der Dienst der Website konnte nicht für Node :target aktualisiert werden. Nichts wurde geändert; sie läuft weiter mit Node :current.',
        'install_pm2' => 'PM2 konnte nicht in Node :target installiert werden, was diese Website für mehrere Prozesse braucht. Nichts wurde geändert; sie läuft weiter mit Node :current.',
        'worker' => 'Die Umstellung auf Node :target wurde vor dem Ende abgebrochen. Die Anwendung ist als Node :current erfasst; prüfen Sie, ob sie läuft, und versuchen Sie es erneut.',
    ],
    'remove_failed' => 'Node :version konnte nicht entfernt werden. Nennen Sie dem Support die Referenz unten.',
    'remove_failed_said' => 'Node :version konnte nicht entfernt werden. fnm meldete: „:output“',
    'install_in_progress' => 'Node :version wird bereits installiert. Warten Sie, bis diese Installation abgeschlossen ist.',
];
