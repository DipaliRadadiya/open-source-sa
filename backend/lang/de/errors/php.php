<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version ist nicht installiert.',

    // Version lookup, the ini editor and its rollback.
    'unknown_version' => 'PHP :version ist auf diesem Server nicht installiert.',
    'unreadable' => 'Die Konfiguration von PHP :version konnte nicht gelesen werden.',
    'invalid_ini' => 'PHP hat diese Konfiguration abgelehnt, daher wurde die vorherige wiederhergestellt. Es wurde nichts neu geladen.',
    'reload_failed' => 'Die Änderung wurde vorgenommen, aber PHP :version konnte nicht neu geladen werden und ist daher noch nicht aktiv. Nennen Sie dem Support die Referenz.',
    'operation_failed' => 'Die Konfiguration von PHP :version konnte nicht aktualisiert werden.',
    'version_in_use' => 'PHP :version wird von :apps verwendet. Ändern Sie zuerst diese Seiten.',
    'version_is_default' => 'Dies ist die Standardversion. Wählen Sie zuerst eine andere.',
    'version_runs_panel' => 'PHP :version zu entfernen würde das Panel offline nehmen — es ist die Version, auf der das Panel selbst läuft.',
    'extension_builtin' => ':extension ist fest in PHP eingebaut und kann nicht deaktiviert werden.',
    'extension_runs_panel' => ':extension zu deaktivieren würde das Panel offline nehmen — es benötigt :modules.',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'Das wird vom PHP-Stack :stack nicht unterstützt.',

    'ioncube_unsupported_version' => 'ionCube veröffentlicht keinen Loader für PHP :version.',
    'ioncube_unsupported_architecture' => 'ionCube veröffentlicht keinen Loader für die Architektur dieses Servers (:architecture).',
    'ioncube_external' => 'ionCube für PHP :version wurde außerhalb des Panels installiert (von einer früheren Panel-Version oder einem Systempaket), daher ändert das Panel es nicht. Es wird zusammen mit PHP :version entfernt.',
    'ioncube_download_failed' => 'Der ionCube-Loader konnte nicht heruntergeladen werden. Prüfen Sie den Internetzugang des Servers und versuchen Sie es erneut.',
    'ioncube_invalid_loader' => 'Die heruntergeladene Datei ist kein gültiger ionCube-Loader für diesen Server. Es wurde nichts installiert.',
    'ioncube_install_failed' => 'Der ionCube-Loader konnte nicht installiert werden.',
    'ioncube_discovery_failed' => 'Die Erkennung des ionCube-Loaders konnte Ihre PHP-Installation nicht sicher ermitteln, daher wurde nichts geändert.',
    'ioncube_extraction_failed' => 'Das ionCube-Archiv konnte nicht entpackt werden.',
    'ioncube_removal_failed' => 'Der zuvor installierte ionCube-Loader konnte nicht entfernt werden. Details finden Sie in der Referenz.',
    'ioncube_reload_failed' => 'PHP konnte nicht neu geladen werden. Die Änderungen sind möglicherweise noch nicht aktiv. Alle Wiederherstellungskopien wurden aufbewahrt.',
    'ioncube_rollback_failed' => 'Das Zurücksetzen ist fehlgeschlagen; alle Wiederherstellungskopien wurden aufbewahrt. Eine manuelle Wiederherstellung ist erforderlich.',
    'ioncube_config_test_failed' => 'Die PHP-Konfigurationsprüfung ist fehlgeschlagen. Die vorherigen Konfigurationsdateien wurden wiederhergestellt und PHP wurde nicht neu geladen.',
];
