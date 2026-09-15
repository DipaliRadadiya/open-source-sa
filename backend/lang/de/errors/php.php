<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version ist nicht installiert.',
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
    'ioncube_download_failed' => 'Der ionCube-Loader konnte nicht heruntergeladen werden. Prüfen Sie den Internetzugang des Servers und versuchen Sie es erneut.',
    'ioncube_invalid_loader' => 'Die heruntergeladene Datei ist kein gültiger ionCube-Loader für diesen Server. Es wurde nichts installiert.',
    'ioncube_install_failed' => 'Der ionCube-Loader konnte nicht installiert werden.',
    'ioncube_config_test_failed' => 'PHP ließ sich mit dem ionCube-Loader nicht starten, daher wurde er wieder entfernt. Ihre Websites waren nicht betroffen.',
];
