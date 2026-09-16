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

    'ioncube_unsupported_version' => 'ionCube does not publish a Loader for PHP :version.',
    'ioncube_unsupported_architecture' => 'ionCube does not publish a Loader for this server\'s architecture (:architecture).',
    'ioncube_download_failed' => 'The ionCube Loader could not be downloaded. Check the server\'s internet access and try again.',
    'ioncube_invalid_loader' => 'The downloaded file is not a valid ionCube Loader for this server. Nothing was installed.',
    'ioncube_install_failed' => 'The ionCube Loader could not be installed.',
    'ioncube_discovery_failed' => 'ionCube Loader discovery could not safely determine your PHP installation, so nothing was changed.',
    'ioncube_extraction_failed' => 'The ionCube archive could not be extracted.',
    'ioncube_removal_failed' => 'The previously installed ionCube Loader could not be removed. Check the reference for details.',
    'ioncube_reload_failed' => 'PHP could not be reloaded. The changes may not yet be active. Any recovery copies have been retained.',
    'ioncube_rollback_failed' => 'Rollback failed; any recovery copies have been retained. Manual recovery is required.',
    'ioncube_config_test_failed' => 'PHP configuration validation failed. The previous configuration files were restored and PHP was not reloaded.',
];
