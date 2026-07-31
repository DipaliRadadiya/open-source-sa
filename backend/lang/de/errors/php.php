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

];
