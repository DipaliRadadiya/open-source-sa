<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version n\'est pas installé.',
    'version_in_use' => 'PHP :version est utilisé par :apps. Modifiez d\'abord ces sites.',
    'version_is_default' => 'C\'est la version par défaut. Choisissez-en une autre d\'abord.',
    'version_runs_panel' => 'Supprimer PHP :version mettrait le panneau hors ligne : c\'est la version sur laquelle il fonctionne.',
    'extension_builtin' => 'L\'extension :extension est compilée dans PHP. Elle ne peut pas être désactivée.',
    'extension_runs_panel' => 'Désactiver :extension mettrait le panneau hors ligne : il a besoin de :modules.',
];
