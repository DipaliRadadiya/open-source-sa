<?php

/*
 * Node feature errors.
 */

return [
    'not_installed' => 'Node :version ist nicht installiert.',
    'version_in_use' => 'Node :version wird von :apps verwendet. Stelle diese Websites zuerst auf eine andere Node-Version um (Node.js-Einstellung der Website) oder lösche sie.',
    'version_unknown' => 'Node.js :version existiert nicht. Wähle eine Version aus der Liste.',
    'version_is_default' => 'Dies ist die Standardversion. Wählen Sie zuerst eine andere.',
    'version_runs_panel' => 'Das Panel selbst läuft mit Node :version. Diese Version kann nicht entfernt werden.',
    'npm_target_unknown' => 'Die npm-Versionsliste war nicht erreichbar, daher lässt sich nicht feststellen, welches npm diese Node-Version ausführen kann. Versuchen Sie es erneut, wenn der Server Internetzugang hat, oder führen Sie `php artisan runtimes:refresh-npm` aus.',
    'not_a_node_server' => 'Dieser Server hostet Container und führt auf dem Host selbst keine Anwendungen aus, daher gibt es keine Node.js-Versionen zu verwalten. Ein Container bringt seine eigene Laufzeitumgebung mit.',
    'change_not_node' => 'Nur eine Website, die mit Node.js läuft, hat eine Node-Version, die man ändern kann.',
    'change_legacy_pm2' => 'Diese Website läuft noch unter PM2 aus dem vorherigen Panel. Stelle sie zuerst auf den Prozessmanager des Panels um und ändere dann ihre Node-Version.',
    'change_in_progress' => 'Diese Website wird bereits auf Node :version umgestellt. Warte, bis das abgeschlossen ist.',
    'change_use_endpoint' => 'Die Node-Version wird mit einer eigenen Aktion geändert (PUT /applications/{application}/node-version), die die Website neu startet und prüft.',
    'change_failed' => [
        'did_not_start' => 'Die Website ist mit Node :target nicht gestartet, daher wurde sie auf Node :current zurückgestellt und läuft wie zuvor. Den Grund findest du im Log der Website.',
        'rollback_failed' => 'Die Website ist mit Node :target nicht gestartet, und das Zurückstellen auf Node :current hat auch nicht funktioniert. Prüfe das Log der Website und starte sie neu.',
        'unit_write' => 'Der Dienst der Website konnte nicht für Node :target aktualisiert werden. Nichts wurde geändert; sie läuft weiter mit Node :current.',
        'install_pm2' => 'PM2 konnte nicht in Node :target installiert werden, was diese Website für mehrere Prozesse braucht. Nichts wurde geändert; sie läuft weiter mit Node :current.',
        'worker' => 'Die Umstellung auf Node :target wurde vor dem Ende abgebrochen. Die Website ist als Node :current erfasst; prüfe, ob sie läuft, und versuche es erneut.',
    ],
];
