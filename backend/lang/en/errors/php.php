<?php

/*
 * PHP feature errors. Split from errors/runtime.php when PHP became its own
 * feature: the shared keys carried a :runtime placeholder so Node and PHP
 * could share a sentence, which is a coupling neither needed.
 */

return [
    'not_installed' => 'PHP :version is not installed.',
    'version_in_use' => 'PHP :version is used by :apps. Change those sites first.',
    'version_is_default' => 'This is the default version. Choose another default first.',
    'version_runs_panel' => 'Removing PHP :version would take the panel offline — it is the version the panel itself runs on.',
    'extension_builtin' => ':extension is compiled into PHP. It cannot be turned off.',
    'extension_runs_panel' => 'Turning :extension off would take the panel offline — it needs :modules.',

    // LSPHP has no phpenmod equivalent. Refusing beats a control that
    // reports success and changes nothing.
    'unsupported_on_stack' => 'This is not supported on the :stack PHP stack.',

];
