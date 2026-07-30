<?php

return [
    'not_installed' => ':runtime :version is not installed.',
    'version_in_use' => ':runtime :version is used by :apps. Change those sites first.',
    'version_is_default' => 'This is the default version. Choose another default first.',
    'version_runs_panel' => 'Removing PHP :version would take the panel offline — it is the version the panel itself runs on.',
    'extension_builtin' => ':extension is compiled into PHP. It cannot be turned off.',
    'extension_runs_panel' => 'Turning :extension off would take the panel offline — it needs :modules.',
];
